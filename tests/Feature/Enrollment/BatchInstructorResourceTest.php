<?php

declare(strict_types=1);

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
    // account, an administrator, or somebody with no employment record.
    $sara = ($this->makeInstructor)('Sara');

    $departed = ($this->makeInstructor)('Departed');
    $departed->forceFill(['is_active' => false])->saveQuietly();

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
        ->and($options)->not->toHaveKey($departed->getKey())
        ->and($options)->not->toHaveKey($clerk->getKey())
        ->and($options)->not->toHaveKey($accountOnly->getKey());
});

it('refuses an ineligible instructor smuggled past the select', function () {
    // The options list is a suggestion; the submitted value is user input. The
    // Action is what refuses it, and nothing is written.
    $clerk = User::factory()->create(['is_active' => true, 'name' => 'Clerk']);
    StaffProfile::factory()->for($clerk)->administrative()->create();

    ($this->mountPanel)(($this->makeUser)('admin'))
        ->callTableAction('assign', null, [
            'user_id' => $clerk->getKey(),
            'assigned_hours' => 30,
        ]);

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
