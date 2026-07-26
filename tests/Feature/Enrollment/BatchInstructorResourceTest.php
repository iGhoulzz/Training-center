<?php

declare(strict_types=1);

use App\Domain\Enrollment\Actions\AssignInstructorAction;
use App\Domain\Enrollment\Actions\RemoveInstructorAction;
use App\Domain\Enrollment\Data\AssignInstructorData;
use App\Domain\Enrollment\Filament\Resources\BatchResource\Pages\ListBatches;
use App\Domain\Enrollment\Filament\Resources\BatchResource\Pages\ViewBatch;
use App\Domain\Enrollment\Filament\Resources\BatchResource\RelationManagers\InstructorsRelationManager;
use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Course;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Domain\Staff\Models\StaffProfile;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Schemas\Schema;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * Instructor allocation as it is actually reachable through the admin panel.
 *
 * InstructorHoursTest proves the rules; this proves the UI is wired to them —
 * that every write goes through the Actions, that the mismatch warning is
 * visible, that the list does not fall into an N+1, and that the refusals hold
 * against a crafted Livewire mount rather than merely a hidden button.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    $this->system = app(SystemRoleWriter::class);

    $this->makeUser = function (string $role): User {
        $user = User::factory()->create(['is_active' => true]);
        $this->system->assignRoles($user, $role);

        return $user->refresh();
    };

    $this->userWith = function (string ...$permissions): User {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(...$permissions);

        return $user->fresh();
    };

    $this->makeInstructor = function (string $name): User {
        $user = User::factory()->create(['is_active' => true, 'name' => $name]);
        StaffProfile::factory()->for($user)->instructor()->create(['job_title' => 'Language tutor']);

        return $user->refresh();
    };

    $this->course = Course::factory()->create(['total_hours' => 30]);
    $this->batch = Batch::factory()->for($this->course)->active()->create(['total_hours' => null]);

    /** Mount the relation manager on the batch's view page as the given actor. */
    $this->mountPanel = fn (User $actor, ?Batch $batch = null) => Livewire::actingAs($actor)
        ->test(InstructorsRelationManager::class, [
            'ownerRecord' => $batch ?? $this->batch,
            'pageClass' => ViewBatch::class,
        ]);
});

/*
|--------------------------------------------------------------------------
| The hour-allocation badge on the schedule
|--------------------------------------------------------------------------
*/

it('shows assigned hours against the batch total in the schedule', function () {
    $sara = ($this->makeInstructor)('Sara');
    $omar = ($this->makeInstructor)('Omar');

    $matched = Batch::factory()->for($this->course)->active()->create(['code' => 'ENG-MATCHED']);
    $matched->instructors()->attach([
        $sara->getKey() => ['assigned_hours' => 18],
        $omar->getKey() => ['assigned_hours' => 12],
    ]);

    $coTaught = Batch::factory()->for($this->course)->active()->create(['code' => 'ENG-COTAUGHT']);
    $coTaught->instructors()->attach([
        $sara->getKey() => ['assigned_hours' => 30],
        $omar->getKey() => ['assigned_hours' => 30],
    ]);

    Livewire::actingAs(($this->makeUser)('admin'))
        ->test(ListBatches::class)
        ->assertTableColumnExists('hour_allocation')
        ->assertTableColumnStateSet('hour_allocation', '30 / 30', $matched)
        // Co-teaching: 60 against 30, shown rather than prevented.
        ->assertTableColumnStateSet('hour_allocation', '60 / 30', $coTaught)
        // Nobody assigned at all reads as 0, not as a blank.
        ->assertTableColumnStateSet('hour_allocation', '0 / 30', $this->batch);
});

it('badges a mismatch as a warning and a match as a success', function () {
    // Amber is the whole point of the column: the co-teaching case is warned
    // about, not blocked, and the matched case must not be warned about at all.
    $sara = ($this->makeInstructor)('Sara');
    $omar = ($this->makeInstructor)('Omar');

    $matched = Batch::factory()->for($this->course)->active()->create();
    $matched->instructors()->attach([$sara->getKey() => ['assigned_hours' => 30]]);

    $coTaught = Batch::factory()->for($this->course)->active()->create();
    $coTaught->instructors()->attach([
        $sara->getKey() => ['assigned_hours' => 30],
        $omar->getKey() => ['assigned_hours' => 30],
    ]);

    $column = Livewire::actingAs(($this->makeUser)('admin'))
        ->test(ListBatches::class)
        ->instance()
        ->getTable()
        ->getColumn('hour_allocation');

    // The colour closure reads the record, not the state, so the state argument
    // is immaterial here.
    expect($column->record($matched->fresh())->getColor(null))->toBe('success')
        ->and($column->record($coTaught->fresh())->getColor(null))->toBe('warning');
});

it('renders the schedule with one aggregate query, not one per batch', function () {
    // The column asks every row for its total assigned hours. Resolved per row
    // that is one SELECT per batch — invisible on a seeded database, ruinous on
    // a real one. withSum() in BatchResource::getEloquentQuery() makes it a
    // sub-select of the single list query, and Batch::totalAssignedHours() reads
    // that alias instead of querying.
    $sara = ($this->makeInstructor)('Sara');
    $omar = ($this->makeInstructor)('Omar');

    // Six batches: five with instructors, one deliberately without. The empty
    // one aggregates to NULL, and a null-coalescing lookup would send exactly
    // that row back to the database — an N+1 visible only on the rows nobody
    // thinks to check.
    foreach (range(1, 5) as $index) {
        $batch = Batch::factory()->for($this->course)->active()->create(['code' => "ENG-N{$index}"]);
        $batch->instructors()->attach([
            $sara->getKey() => ['assigned_hours' => 18],
            $omar->getKey() => ['assigned_hours' => 12],
        ]);
    }

    $admin = ($this->makeUser)('admin');

    $pivotQueries = [];
    $capturing = true;

    DB::listen(function (QueryExecuted $query) use (&$pivotQueries, &$capturing): void {
        if ($capturing && str_contains($query->sql, 'batch_instructor')) {
            $pivotQueries[] = $query->sql;
        }
    });

    /*
     * The measurement is the RENDER, and nothing after it. Livewire::test()
     * builds and renders the table — the state and colour closures for every row
     * run here, which is why a broken column blows up on this line rather than
     * on an assertion. Filament's assertion helpers then re-resolve the record
     * they are given with a second, single-row query; counting those would
     * measure the test harness rather than the page.
     */
    $component = Livewire::actingAs($admin)->test(ListBatches::class);

    $capturing = false;

    expect($pivotQueries)->toHaveCount(
        1,
        'Rendering 6 batches ran '.count($pivotQueries).' queries against batch_instructor. '
        .'One aggregate sub-select is expected; more than one means the column is resolving per row.',
    );

    // Rendered, and rendered correctly — including the row with no instructors,
    // whose aggregate is NULL rather than 0.
    $component
        ->assertTableColumnStateSet('hour_allocation', '30 / 30', Batch::where('code', 'ENG-N1')->firstOrFail())
        ->assertTableColumnStateSet('hour_allocation', '0 / 30', $this->batch);
});

/*
|--------------------------------------------------------------------------
| The relation manager writes only through the Actions
|--------------------------------------------------------------------------
*/

it('assigns an instructor through the relation manager', function () {
    $sara = ($this->makeInstructor)('Sara');

    ($this->mountPanel)(($this->makeUser)('admin'))
        ->callTableAction('assign', null, [
            'user_id' => $sara->getKey(),
            'assigned_hours' => 18,
        ])
        ->assertHasNoTableActionErrors();

    expect($this->batch->fresh()->totalAssignedHours())->toBe(18)
        ->and(DB::table('batch_instructor')->count())->toBe(1);
});

it('updates the hours of an instructor already on the batch', function () {
    $sara = ($this->makeInstructor)('Sara');
    $this->batch->instructors()->attach([$sara->getKey() => ['assigned_hours' => 18]]);

    ($this->mountPanel)(($this->makeUser)('admin'))
        ->callTableAction('edit_hours', $sara, ['assigned_hours' => 24])
        ->assertHasNoTableActionErrors();

    expect($this->batch->fresh()->totalAssignedHours())->toBe(24)
        // Updated, not duplicated.
        ->and(DB::table('batch_instructor')->count())->toBe(1);
});

it('removes only the named instructor through the relation manager', function () {
    $sara = ($this->makeInstructor)('Sara');
    $omar = ($this->makeInstructor)('Omar');
    $this->batch->instructors()->attach([
        $sara->getKey() => ['assigned_hours' => 18],
        $omar->getKey() => ['assigned_hours' => 12],
    ]);

    ($this->mountPanel)(($this->makeUser)('admin'))
        ->callTableAction('remove', $omar);

    $batch = $this->batch->fresh();

    expect($batch->instructors)->toHaveCount(1)
        ->and($batch->instructors->first()->getKey())->toBe($sara->getKey());
});

it('shows the assigned hours for each instructor in the panel', function () {
    $sara = ($this->makeInstructor)('Sara');
    $omar = ($this->makeInstructor)('Omar');
    $this->batch->instructors()->attach([
        $sara->getKey() => ['assigned_hours' => 18],
        $omar->getKey() => ['assigned_hours' => 12],
    ]);

    ($this->mountPanel)(($this->makeUser)('admin'))
        ->assertTableColumnStateSet('assigned_hours', 18, $sara)
        ->assertTableColumnStateSet('assigned_hours', 12, $omar);
});

it('offers only active instructors when assigning', function () {
    // The Select must not offer a name the Action would refuse: a deactivated
    // account, a soft-deleted one, an administrator, or somebody with no
    // employment record.
    $sara = ($this->makeInstructor)('Sara');

    $deactivated = ($this->makeInstructor)('Deactivated');
    $deactivated->forceFill(['is_active' => false])->saveQuietly();

    // Departed, and still holding hours — so the relation lists them while this
    // list must not. The two queries answer different questions on purpose.
    $departed = ($this->makeInstructor)('Departed');
    $this->batch->instructors()->attach([$departed->getKey() => ['assigned_hours' => 12]]);
    $departed->delete();

    $clerk = User::factory()->create(['is_active' => true, 'name' => 'Clerk']);
    StaffProfile::factory()->for($clerk)->administrative()->create();

    $accountOnly = User::factory()->create(['is_active' => true, 'name' => 'Account Only']);

    $panel = ($this->mountPanel)(($this->makeUser)('admin'))->instance();

    $options = $panel
        ->getTable()
        ->getAction('assign')
        ->getSchema(Schema::make($panel))
        ?->getComponent('user_id')
        ?->getOptions();

    expect($options)->toBe([$sara->getKey() => 'Sara'])
        ->and($options)->not->toHaveKey($deactivated->getKey())
        ->and($options)->not->toHaveKey($departed->getKey())
        ->and($options)->not->toHaveKey($clerk->getKey())
        ->and($options)->not->toHaveKey($accountOnly->getKey())
        // The control: the departed instructor IS still on the batch. If the
        // relation had dropped them, "absent from the options" would be proving
        // nothing about the options.
        ->and($this->batch->fresh()->instructors)->toHaveCount(1);
});

/*
|--------------------------------------------------------------------------
| Departed instructors, as the panel presents them
|--------------------------------------------------------------------------
|
| Batch::instructors() is withTrashed(): the pivot row survives a soft delete
| and phase 2 pays wages from it, so the panel that claims to list a batch's
| allocations has to list it. These tests pin that it is listed, that it is
| counted, and that it is visibly marked as history rather than passing for a
| current member of staff.
*/

it('keeps a departed instructor visible in the panel, marked as departed', function () {
    $sara = ($this->makeInstructor)('Sara');
    $omar = ($this->makeInstructor)('Omar');
    $this->batch->instructors()->attach([
        $sara->getKey() => ['assigned_hours' => 18],
        $omar->getKey() => ['assigned_hours' => 12],
    ]);

    $omar->delete();

    ($this->mountPanel)(($this->makeUser)('admin'))
        // Both rows are rendered, the departed one included.
        ->assertCanSeeTableRecords([$sara, $omar])
        // With their hours intact — the number phase 2 pays from.
        ->assertTableColumnStateSet('assigned_hours', 12, $omar)
        ->assertTableColumnStateSet('assigned_hours', 18, $sara)
        // And visibly distinguished, in a translated string rather than a bare
        // date or a blank cell.
        ->assertTableColumnExists('employment_state')
        ->assertTableColumnStateSet('employment_state', __('enrollment.instructor_departed'), $omar)
        ->assertTableColumnStateSet('employment_state', __('enrollment.instructor_current'), $sara);
});

it('badges the departed row in danger and the current one in success', function () {
    $sara = ($this->makeInstructor)('Sara');
    $omar = ($this->makeInstructor)('Omar');
    $this->batch->instructors()->attach([
        $sara->getKey() => ['assigned_hours' => 18],
        $omar->getKey() => ['assigned_hours' => 12],
    ]);

    $omar->delete();

    $column = ($this->mountPanel)(($this->makeUser)('admin'))
        ->instance()
        ->getTable()
        ->getColumn('employment_state');

    // The colour closure reads the record, not the state.
    expect($column->record($this->batch->fresh()->instructors->firstWhere('id', $omar->getKey()))->getColor(null))
        ->toBe('danger')
        ->and($column->record($this->batch->fresh()->instructors->firstWhere('id', $sara->getKey()))->getColor(null))
        ->toBe('success');
});

it('counts a departed instructor in the schedule hour badge', function () {
    // The listing path. If the aggregate dropped Omar's twelve hours while the
    // panel still showed them, the two screens would disagree about what the
    // centre owes — and only one of them would be right.
    $sara = ($this->makeInstructor)('Sara');
    $omar = ($this->makeInstructor)('Omar');

    $this->batch->instructors()->attach([
        $sara->getKey() => ['assigned_hours' => 18],
        $omar->getKey() => ['assigned_hours' => 12],
    ]);

    $omar->delete();

    Livewire::actingAs(($this->makeUser)('admin'))
        ->test(ListBatches::class)
        ->assertTableColumnStateSet('hour_allocation', '30 / 30', $this->batch);
});

it('removes a departed instructor from an open batch through the panel', function () {
    // A departed person mis-assigned in the first place must still be
    // removable, or the mistake is permanent.
    $sara = ($this->makeInstructor)('Sara');
    $omar = ($this->makeInstructor)('Omar');
    $this->batch->instructors()->attach([
        $sara->getKey() => ['assigned_hours' => 18],
        $omar->getKey() => ['assigned_hours' => 12],
    ]);

    $omar->delete();

    ($this->mountPanel)(($this->makeUser)('admin'))
        ->callTableAction('remove', $omar);

    expect(DB::table('batch_instructor')->count())->toBe(1)
        ->and((int) DB::table('batch_instructor')->value('user_id'))->toBe($sara->getKey())
        // The account is not deleted a second time, only the allocation.
        ->and(User::withTrashed()->whereKey($omar->getKey())->exists())->toBeTrue();
});

it('refuses to remove a departed instructor from a closed batch', function () {
    $omar = ($this->makeInstructor)('Omar');
    $closed = Batch::factory()->for($this->course)->completed()->create();
    $closed->instructors()->attach([$omar->getKey() => ['assigned_hours' => 12]]);

    $omar->delete();

    $context = ['table' => true, 'recordKey' => (string) $omar->getKey()];

    // CONTROL: the same action, the same departed instructor, on an OPEN batch.
    $this->batch->instructors()->attach([$omar->getKey() => ['assigned_hours' => 12]]);
    $control = ($this->mountPanel)(($this->makeUser)('admin'));
    $control->call('mountAction', 'remove', [], $context);
    expect($control->get('mountedActions'))->not->toBeEmpty(
        'The remove action did not resolve for a departed instructor on an open batch — the probe below would prove nothing.'
    );

    $component = ($this->mountPanel)(($this->makeUser)('admin'), $closed);
    $component->call('mountAction', 'remove', [], $context);

    expect($component->get('mountedActions'))->toBeEmpty();

    $component->call('callMountedAction');

    expect(DB::table('batch_instructor')->where('batch_id', $closed->getKey())->count())->toBe(1);
});

it('refuses to assign a departed instructor smuggled past the select', function () {
    // Same two layers as the ineligible clerk above. The departed instructor is
    // absent from the options, so the derived `in` rule rejects the id here;
    // InstructorHoursTest proves the Action refuses it with or without a form.
    $departed = ($this->makeInstructor)('Departed');
    $departed->delete();

    ($this->mountPanel)(($this->makeUser)('admin'))
        ->callTableAction('assign', null, [
            'user_id' => $departed->getKey(),
            'assigned_hours' => 30,
        ])
        ->assertHasTableActionErrors(['user_id']);

    expect(DB::table('batch_instructor')->count())->toBe(0);
});

it('refuses to rewrite a departed instructor\'s hours from the panel', function () {
    /*
     * The edit button IS offered on a departed row, and refuses.
     *
     * That is deliberate. Hiding it would leave a row whose hours visibly
     * cannot be changed with no statement of why, and the reason is worth
     * saying: those hours are what phase 2 pays a person who has left, so
     * rewriting them is rewriting a wage. The refusal is the Action's, arrives
     * as a translated message, and the stored figure does not move.
     *
     * This is also the one path where a typed refusal from an Action reaches
     * the panel through a real user gesture — the assign modal's Select rejects
     * ineligible ids at validation, and every status refusal hides its button —
     * so it is what proves refuse() passes those messages through at all.
     */
    $omar = ($this->makeInstructor)('Omar');
    $this->batch->instructors()->attach([$omar->getKey() => ['assigned_hours' => 12]]);
    $omar->delete();

    ($this->mountPanel)(($this->makeUser)('admin'))
        ->callTableAction('edit_hours', $omar, ['assigned_hours' => 40])
        // Not a validation failure: 40 is a perfectly good number of hours.
        ->assertHasNoTableActionErrors()
        ->assertNotified(__('enrollment.instructor_not_eligible'))
        // The typed refusal keeps its own words; only AuthorizationException is
        // rewritten.
        ->assertNotNotified(__('enrollment.instructor_change_denied'));

    expect((int) DB::table('batch_instructor')->value('assigned_hours'))->toBe(12);
});

it('refuses an ineligible instructor smuggled past the select', function () {
    // The submitted value is user input, not a promise that it came from the
    // list. Two things refuse it, and this pins the FIRST: Select derives an
    // `in` rule from its options, so an id that was never offered fails
    // validation before any handler runs. AssignInstructorAction is the second
    // and the binding one — InstructorHoursTest calls it directly, without a
    // form in the way, precisely because a rule derived from options would
    // otherwise be the only thing tested here.
    $clerk = User::factory()->create(['is_active' => true, 'name' => 'Clerk']);
    StaffProfile::factory()->for($clerk)->administrative()->create();

    ($this->mountPanel)(($this->makeUser)('admin'))
        ->callTableAction('assign', null, [
            'user_id' => $clerk->getKey(),
            'assigned_hours' => 30,
        ])
        ->assertHasTableActionErrors(['user_id']);

    expect(DB::table('batch_instructor')->count())->toBe(0);
});

it('registers no bulk actions on the instructors panel', function () {
    // A bulk detach authorizes once against a *Any policy method and would call
    // the relation's detach() directly, skipping the closed-batch refusal and
    // the actor check entirely.
    $table = ($this->mountPanel)(($this->makeUser)('super_admin'))
        ->instance()
        ->getTable();

    expect($table->getFlatBulkActions())->toBeEmpty()
        ->and($table->getToolbarActions())->toBeEmpty();
});

/*
|--------------------------------------------------------------------------
| Who can see the panel at all
|--------------------------------------------------------------------------
*/

it('shows the instructors panel to anyone who may view the batch', function () {
    // The inherited canViewForRecord() would authorize viewAny against
    // App\Models\User and hide this from every front-desk user, who hold
    // view_batch and no user permission at all.
    $staff = ($this->makeUser)('staff');

    expect($staff->can('view_any_user'))->toBeFalse()
        ->and(InstructorsRelationManager::canViewForRecord($this->batch, ViewBatch::class))->toBeFalse();

    $this->actingAs($staff);

    expect(InstructorsRelationManager::canViewForRecord($this->batch, ViewBatch::class))->toBeTrue();
});

it('hides the instructors panel from an actor who may not view the batch', function () {
    $outsider = ($this->userWith)('access_admin_panel');

    $this->actingAs($outsider);

    expect(InstructorsRelationManager::canViewForRecord($this->batch, ViewBatch::class))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Server-side authorization probes (control first)
|--------------------------------------------------------------------------
|
| mountAction() unmounts and returns null for an unresolvable name, a disabled
| action AND an unauthorized one, so a probe that only checks "nothing mounted"
| cannot tell a refusal from a typo. EVERY probe below first mounts the SAME
| action, on the same component, as an authorized actor and asserts the stack is
| not empty. Without that control the refusal assertion proves nothing.
*/

it('refuses an assign mounted directly by an actor without assign_instructor', function () {
    $sara = ($this->makeInstructor)('Sara');

    // A header action resolves only with table context.
    $context = ['table' => true];

    // CONTROL: the action resolves for an actor who may assign.
    $control = ($this->mountPanel)(($this->makeUser)('admin'));
    $control->call('mountAction', 'assign', [], $context);
    expect($control->get('mountedActions'))->not->toBeEmpty(
        'The assign action did not resolve even for an admin — the probe below would prove nothing.'
    );

    // The probe: staff may read the schedule and nothing more.
    $component = ($this->mountPanel)(($this->makeUser)('staff'));
    $component->call('mountAction', 'assign', [], $context);

    expect($component->get('mountedActions'))->toBeEmpty();

    // Call it anyway, the way a crafted client would after a failed mount.
    $component->call('callMountedAction', ['user_id' => $sara->getKey(), 'assigned_hours' => 30]);

    expect(DB::table('batch_instructor')->count())->toBe(0);
});

it('refuses a remove mounted directly by an actor without assign_instructor', function () {
    $sara = ($this->makeInstructor)('Sara');
    $this->batch->instructors()->attach([$sara->getKey() => ['assigned_hours' => 30]]);

    $context = ['table' => true, 'recordKey' => (string) $sara->getKey()];

    $control = ($this->mountPanel)(($this->makeUser)('admin'));
    $control->call('mountAction', 'remove', [], $context);
    expect($control->get('mountedActions'))->not->toBeEmpty(
        'The remove action did not resolve even for an admin — the probe below would prove nothing.'
    );

    $component = ($this->mountPanel)(($this->makeUser)('staff'));
    $component->call('mountAction', 'remove', [], $context);

    expect($component->get('mountedActions'))->toBeEmpty();

    $component->call('callMountedAction');

    expect(DB::table('batch_instructor')->count())->toBe(1)
        ->and((int) DB::table('batch_instructor')->value('assigned_hours'))->toBe(30);
});

it('refuses an hours edit mounted directly by an actor who may update the batch but not assign', function () {
    // update_batch and assign_instructor are separate grants, and this is the
    // actor who has one without the other.
    $sara = ($this->makeInstructor)('Sara');
    $this->batch->instructors()->attach([$sara->getKey() => ['assigned_hours' => 18]]);

    $context = ['table' => true, 'recordKey' => (string) $sara->getKey()];

    $control = ($this->mountPanel)(($this->makeUser)('admin'));
    $control->call('mountAction', 'edit_hours', [], $context);
    expect($control->get('mountedActions'))->not->toBeEmpty(
        'The edit_hours action did not resolve even for an admin — the probe below would prove nothing.'
    );

    $editor = ($this->userWith)('access_admin_panel', 'view_any_batch', 'view_batch', 'update_batch');

    $component = ($this->mountPanel)($editor);
    $component->call('mountAction', 'edit_hours', [], $context);

    expect($component->get('mountedActions'))->toBeEmpty();

    $component->call('callMountedAction', ['assigned_hours' => 999]);

    expect((int) DB::table('batch_instructor')->value('assigned_hours'))->toBe(18);
});

it('refuses an assign mounted directly against a completed batch', function () {
    // BatchPolicy::assignInstructor() carries the status gate, so even a super
    // admin cannot mount this on a closed batch — and AssignInstructorAction
    // re-checks it against the freshly locked row regardless.
    $sara = ($this->makeInstructor)('Sara');
    $closed = Batch::factory()->for($this->course)->completed()->create();

    $context = ['table' => true];

    // CONTROL: the same actor, the same action, on an OPEN batch.
    $control = ($this->mountPanel)(($this->makeUser)('admin'));
    $control->call('mountAction', 'assign', [], $context);
    expect($control->get('mountedActions'))->not->toBeEmpty(
        'The assign action did not resolve on an open batch — the probe below would prove nothing.'
    );

    $component = ($this->mountPanel)(($this->makeUser)('admin'), $closed);
    $component->call('mountAction', 'assign', [], $context);

    expect($component->get('mountedActions'))->toBeEmpty();

    $component->call('callMountedAction', ['user_id' => $sara->getKey(), 'assigned_hours' => 30]);

    expect(DB::table('batch_instructor')->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| The hours field rejects what the handlers would otherwise cast to zero
|--------------------------------------------------------------------------
|
| Both handlers do `(int) $data['assigned_hours']`. A blank box, a word, or a
| figure the column cannot hold would all become 0 or wrap, silently and
| plausibly — a real allocation quietly replaced by "assigned, no hours", which
| phase 2 then pays nothing for. Each rule below is asserted as a FORM ERROR, so
| a rule that stops being applied fails here rather than passing as a no-op.
*/

it('rejects a blank, non-numeric or out-of-range hours figure when assigning', function () {
    $sara = ($this->makeInstructor)('Sara');

    $assignWith = fn (mixed $hours) => ($this->mountPanel)(($this->makeUser)('admin'))
        ->callTableAction('assign', null, [
            'user_id' => $sara->getKey(),
            'assigned_hours' => $hours,
        ]);

    $assignWith(null)->assertHasTableActionErrors(['assigned_hours' => ['required']]);
    $assignWith('')->assertHasTableActionErrors(['assigned_hours' => ['required']]);
    $assignWith('thirty')->assertHasTableActionErrors(['assigned_hours' => ['integer']]);
    $assignWith('18.5')->assertHasTableActionErrors(['assigned_hours' => ['integer']]);
    $assignWith(-1)->assertHasTableActionErrors(['assigned_hours' => ['min']]);
    // 65535 is the unsignedSmallInteger ceiling; one past it must not wrap.
    $assignWith(65_536)->assertHasTableActionErrors(['assigned_hours' => ['max']]);

    // Nothing was written by any of them — the point of validating rather than
    // casting.
    expect(DB::table('batch_instructor')->count())->toBe(0);

    // The control: the same field accepts the boundary values it is meant to.
    $assignWith(0)->assertHasNoTableActionErrors();
    expect((int) DB::table('batch_instructor')->value('assigned_hours'))->toBe(0);

    $assignWith(65_535)->assertHasNoTableActionErrors();
    expect((int) DB::table('batch_instructor')->value('assigned_hours'))->toBe(65_535);
});

it('rejects a blank, non-numeric or out-of-range hours figure when editing', function () {
    // The edit modal is a separate schema from the assign modal, so its rules
    // are separately droppable — and separately asserted.
    $sara = ($this->makeInstructor)('Sara');
    $this->batch->instructors()->attach([$sara->getKey() => ['assigned_hours' => 18]]);

    $editWith = fn (mixed $hours) => ($this->mountPanel)(($this->makeUser)('admin'))
        ->callTableAction('edit_hours', $sara, ['assigned_hours' => $hours]);

    $editWith(null)->assertHasTableActionErrors(['assigned_hours' => ['required']]);
    $editWith('')->assertHasTableActionErrors(['assigned_hours' => ['required']]);
    $editWith('twenty')->assertHasTableActionErrors(['assigned_hours' => ['integer']]);
    $editWith('20.5')->assertHasTableActionErrors(['assigned_hours' => ['integer']]);
    $editWith(-1)->assertHasTableActionErrors(['assigned_hours' => ['min']]);
    $editWith(65_536)->assertHasTableActionErrors(['assigned_hours' => ['max']]);

    // The existing allocation survived every one of them untouched. Without the
    // rules, the first would have overwritten 18 with 0.
    expect((int) DB::table('batch_instructor')->value('assigned_hours'))->toBe(18);

    $editWith(24)->assertHasNoTableActionErrors();
    expect((int) DB::table('batch_instructor')->value('assigned_hours'))->toBe(24);
});

/*
|--------------------------------------------------------------------------
| A refusal is readable, and translatable
|--------------------------------------------------------------------------
*/

it('reports an authorization refusal from the assign Action through a translated key', function () {
    /*
     * AuthorizationException out of the Action is DEFENCE IN DEPTH, and cannot
     * be provoked through the panel: Filament hides the button for an
     * unentitled actor and isDisabled() consults isHidden(), so a mounted action
     * whose grant disappears is dropped before the handler runs. The Action is
     * therefore stubbed — the branch under test is not the Action's refusal but
     * what the relation manager DOES with one.
     *
     * Left unmapped it renders Laravel's "This action is unauthorized.":
     * hardcoded English that would sit untranslated in the Arabic panel phase 4
     * brings, and that says nothing about instructor allocation in any language.
     */
    $sara = ($this->makeInstructor)('Sara');

    $this->app->bind(AssignInstructorAction::class, fn (): object => new class
    {
        public function execute(User $actor, AssignInstructorData $data): void
        {
            throw new AuthorizationException;
        }
    });

    ($this->mountPanel)(($this->makeUser)('admin'))
        ->callTableAction('assign', null, [
            'user_id' => $sara->getKey(),
            'assigned_hours' => 18,
        ])
        ->assertNotified(__('enrollment.instructor_change_denied'))
        ->assertNotNotified((new AuthorizationException)->getMessage());

    expect(DB::table('batch_instructor')->count())->toBe(0);
});

it('reports an authorization refusal from the remove Action through a translated key', function () {
    $sara = ($this->makeInstructor)('Sara');
    $this->batch->instructors()->attach([$sara->getKey() => ['assigned_hours' => 18]]);

    $this->app->bind(RemoveInstructorAction::class, fn (): object => new class
    {
        public function execute(User $actor, Batch $batch, User $instructor): void
        {
            throw new AuthorizationException;
        }
    });

    ($this->mountPanel)(($this->makeUser)('admin'))
        ->callTableAction('remove', $sara)
        ->assertNotified(__('enrollment.instructor_change_denied'))
        ->assertNotNotified((new AuthorizationException)->getMessage());

    expect(DB::table('batch_instructor')->count())->toBe(1);
});

it('refuses a remove mounted directly against a cancelled batch', function () {
    $sara = ($this->makeInstructor)('Sara');
    $cancelled = Batch::factory()->for($this->course)->cancelled()->create();
    $cancelled->instructors()->attach([$sara->getKey() => ['assigned_hours' => 30]]);

    $context = ['table' => true, 'recordKey' => (string) $sara->getKey()];

    // CONTROL: the same action on an open batch, same actor, same instructor.
    $this->batch->instructors()->attach([$sara->getKey() => ['assigned_hours' => 30]]);
    $control = ($this->mountPanel)(($this->makeUser)('admin'));
    $control->call('mountAction', 'remove', [], $context);
    expect($control->get('mountedActions'))->not->toBeEmpty(
        'The remove action did not resolve on an open batch — the probe below would prove nothing.'
    );

    $component = ($this->mountPanel)(($this->makeUser)('admin'), $cancelled);
    $component->call('mountAction', 'remove', [], $context);

    expect($component->get('mountedActions'))->toBeEmpty();

    $component->call('callMountedAction');

    expect(DB::table('batch_instructor')->where('batch_id', $cancelled->getKey())->count())->toBe(1);
});
