<?php

declare(strict_types=1);

use App\Domain\Enrollment\Enums\BatchStatus;
use App\Domain\Enrollment\Filament\Resources\BatchResource;
use App\Domain\Enrollment\Filament\Resources\BatchResource\Pages\CreateBatch;
use App\Domain\Enrollment\Filament\Resources\BatchResource\Pages\EditBatch;
use App\Domain\Enrollment\Filament\Resources\BatchResource\Pages\ListBatches;
use App\Domain\Enrollment\Filament\Resources\BatchResource\Pages\ViewBatch;
use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Course;
use App\Domain\Enrollment\Policies\BatchPolicy;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

/**
 * The batch schedule as it is actually reachable over HTTP.
 *
 * The policy tests prove the rules; these prove the resource is wired to them.
 * Both are needed — a correct policy nobody consults denies nothing.
 *
 * The price assertions here are the phase-discipline guard. batches.price exists
 * in the schema for phase 2's benefit; if anyone helpfully surfaces it in the
 * form or the table, these fail.
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
});

/*
|--------------------------------------------------------------------------
| Reaching the resource at all
|--------------------------------------------------------------------------
*/

it('lets an admin reach the batch schedule', function () {
    $this->actingAs(($this->makeUser)('admin'))
        ->get('/admin/batches')
        ->assertSuccessful();
});

it('lets staff reach the batch schedule', function () {
    $this->actingAs(($this->makeUser)('staff'))
        ->get('/admin/batches')
        ->assertSuccessful();
});

it('denies a student-role user the schedule', function () {
    // Denied twice over: the student role holds neither access_admin_panel nor
    // any batch permission.
    $this->actingAs(($this->makeUser)('student'))
        ->get('/admin/batches')
        ->assertForbidden();
});

it('lets staff open a batch read only but refuses them create and edit', function () {
    $batch = Batch::factory()->create();
    $staff = ($this->makeUser)('staff');

    $this->actingAs($staff)->get("/admin/batches/{$batch->getKey()}")->assertSuccessful();
    $this->actingAs($staff)->get('/admin/batches/create')->assertForbidden();
    $this->actingAs($staff)->get("/admin/batches/{$batch->getKey()}/edit")->assertForbidden();
});

it('refuses a crafted edit submitted by staff, leaving the batch unchanged', function () {
    // The edit page is already refused above; this proves the refusal is not
    // merely a hidden link by driving the component directly.
    $staff = ($this->makeUser)('staff');
    $batch = Batch::factory()->create(['capacity' => 20]);

    Livewire::actingAs($staff)
        ->test(EditBatch::class, ['record' => $batch->getKey()])
        ->assertForbidden();

    expect($batch->fresh()?->capacity)->toBe(20);
});

it('refuses a crafted create submitted by staff', function () {
    $staff = ($this->makeUser)('staff');

    Livewire::actingAs($staff)
        ->test(CreateBatch::class)
        ->assertForbidden();

    expect(Batch::count())->toBe(0);
});

it('gates each resource page on its own permission', function () {
    // The four pages are separate grants. An actor holding only view must not
    // reach create or edit, and each refusal must come from the page itself.
    $batch = Batch::factory()->create();

    $viewer = User::factory()->create(['is_active' => true]);
    $viewer->givePermissionTo('access_admin_panel', 'view_any_batch', 'view_batch');

    $creator = User::factory()->create(['is_active' => true]);
    $creator->givePermissionTo('access_admin_panel', 'view_any_batch', 'view_batch', 'create_batch');

    $editor = User::factory()->create(['is_active' => true]);
    $editor->givePermissionTo('access_admin_panel', 'view_any_batch', 'view_batch', 'update_batch');

    $key = $batch->getKey();

    $this->actingAs($viewer->fresh())->get('/admin/batches')->assertSuccessful();
    $this->actingAs($viewer->fresh())->get("/admin/batches/{$key}")->assertSuccessful();
    $this->actingAs($viewer->fresh())->get('/admin/batches/create')->assertForbidden();
    $this->actingAs($viewer->fresh())->get("/admin/batches/{$key}/edit")->assertForbidden();

    $this->actingAs($creator->fresh())->get('/admin/batches/create')->assertSuccessful();
    $this->actingAs($creator->fresh())->get("/admin/batches/{$key}/edit")->assertForbidden();

    $this->actingAs($editor->fresh())->get("/admin/batches/{$key}/edit")->assertSuccessful();
    $this->actingAs($editor->fresh())->get('/admin/batches/create')->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Writes through the real pages
|--------------------------------------------------------------------------
*/

it('creates a batch through the create page', function () {
    $course = Course::factory()->create(['total_hours' => 30]);

    Livewire::actingAs(($this->makeUser)('admin'))
        ->test(CreateBatch::class)
        ->fillForm([
            'course_id' => $course->getKey(),
            'code' => 'ENG-B1-JAN26',
            'status' => BatchStatus::Planned->value,
            'capacity' => 20,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $batch = Batch::firstOrFail();

    expect($batch->code)->toBe('ENG-B1-JAN26')
        ->and($batch->course_id)->toBe($course->getKey())
        ->and($batch->status)->toBe(BatchStatus::Planned)
        ->and($batch->capacity)->toBe(20)
        // Left blank on the form, so it inherits. Filament must not have
        // coerced the empty input into a zero, which would stop inheritance.
        ->and($batch->total_hours)->toBeNull()
        ->and($batch->effective_total_hours)->toBe(30);
});

it('creates a batch with an explicit hours override', function () {
    $course = Course::factory()->create(['total_hours' => 30]);

    Livewire::actingAs(($this->makeUser)('admin'))
        ->test(CreateBatch::class)
        ->fillForm([
            'course_id' => $course->getKey(),
            'code' => 'ENG-B1-INTENSIVE',
            'status' => BatchStatus::Planned->value,
            'capacity' => 12,
            'total_hours' => 45,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $batch = Batch::firstOrFail();

    expect($batch->total_hours)->toBe(45)
        ->and($batch->effective_total_hours)->toBe(45);
});

it('saves a real edit made by an admin', function () {
    // The refusal paths are covered above; this is the positive control that
    // proves the edit page is not simply broken for everyone.
    $batch = Batch::factory()->create(['capacity' => 20, 'code' => 'ENG-B1-JAN26']);

    Livewire::actingAs(($this->makeUser)('admin'))
        ->test(EditBatch::class, ['record' => $batch->getKey()])
        ->fillForm([
            'capacity' => 25,
            'status' => BatchStatus::Active->value,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $batch->refresh();

    expect($batch->capacity)->toBe(25)
        ->and($batch->status)->toBe(BatchStatus::Active)
        ->and($batch->code)->toBe('ENG-B1-JAN26');
});

it('lets an admin reopen a batch that was marked completed by mistake', function () {
    // The reason BatchPolicy::update() does not gate on status. If it did, this
    // record would be frozen — including its own status column — and a
    // mis-clicked "completed" could never be undone from the application.
    $batch = Batch::factory()->completed()->create();

    Livewire::actingAs(($this->makeUser)('admin'))
        ->test(EditBatch::class, ['record' => $batch->getKey()])
        ->fillForm(['status' => BatchStatus::Active->value])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($batch->fresh()?->status)->toBe(BatchStatus::Active);
});

it('rejects an end date that falls before the start date', function () {
    $course = Course::factory()->create();

    Livewire::actingAs(($this->makeUser)('admin'))
        ->test(CreateBatch::class)
        ->fillForm([
            'course_id' => $course->getKey(),
            'code' => 'ENG-B1-BACKWARDS',
            'status' => BatchStatus::Planned->value,
            'start_date' => '2026-09-01',
            'end_date' => '2026-08-01',
        ])
        ->call('create')
        ->assertHasFormErrors(['end_date']);

    expect(Batch::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Inheritance is visible where it matters
|--------------------------------------------------------------------------
*/

it('shows the inherited hours in the schedule table', function () {
    // The list shows the EFFECTIVE figure, not the raw column, so nobody has to
    // open the course to read it — and so a change to the course is visible
    // here immediately.
    $course = Course::factory()->create(['total_hours' => 30]);
    $inheriting = Batch::factory()->for($course)->create(['total_hours' => null]);
    $overriding = Batch::factory()->for($course)->create(['total_hours' => 45]);

    Livewire::actingAs(($this->makeUser)('admin'))
        ->test(ListBatches::class)
        ->assertTableColumnStateSet('effective_total_hours', 30, $inheriting)
        ->assertTableColumnStateSet('effective_total_hours', 45, $overriding);

    $course->update(['total_hours' => 36]);

    Livewire::actingAs(($this->makeUser)('admin'))
        ->test(ListBatches::class)
        ->assertTableColumnStateSet('effective_total_hours', 36, $inheriting)
        ->assertTableColumnStateSet('effective_total_hours', 45, $overriding);
});

/*
|--------------------------------------------------------------------------
| PHASE DISCIPLINE: no price anywhere in the UI
|--------------------------------------------------------------------------
|
| batches.price is a phase 2 column created early so phase 2 never has to ALTER
| a table holding production data. Phase 1 has no financial features, so the
| field must be unreachable, not merely unused. These are the guard against
| someone helpfully surfacing it later.
*/

it('exposes no price field on the create page', function () {
    Livewire::actingAs(($this->makeUser)('admin'))
        ->test(CreateBatch::class)
        // The control: a field that IS on the form, proving the assertion below
        // is not passing because the form failed to build at all.
        ->assertFormFieldExists('capacity')
        ->assertFormFieldDoesNotExist('price');
});

it('exposes no price field on the edit page', function () {
    $batch = Batch::factory()->create();

    Livewire::actingAs(($this->makeUser)('admin'))
        ->test(EditBatch::class, ['record' => $batch->getKey()])
        ->assertFormFieldExists('capacity')
        ->assertFormFieldDoesNotExist('price');
});

it('exposes no price field on the view page', function () {
    $batch = Batch::factory()->create();

    Livewire::actingAs(($this->makeUser)('admin'))
        ->test(ViewBatch::class, ['record' => $batch->getKey()])
        ->assertFormFieldExists('capacity')
        ->assertFormFieldDoesNotExist('price');
});

it('exposes no price column in the schedule table', function () {
    Batch::factory()->create();

    Livewire::actingAs(($this->makeUser)('admin'))
        ->test(ListBatches::class)
        ->assertTableColumnExists('capacity')
        ->assertTableColumnDoesNotExist('price');
});

it('ignores a price smuggled into the create payload', function () {
    // price IS fillable on the model — phase 2 needs it to be — so the only
    // thing keeping it out of a phase 1 write is its absence from the form.
    // This submits it the way a hand-written Livewire payload would.
    $course = Course::factory()->create();

    Livewire::actingAs(($this->makeUser)('admin'))
        ->test(CreateBatch::class)
        ->fillForm([
            'course_id' => $course->getKey(),
            'code' => 'ENG-CRAFTED',
            'status' => BatchStatus::Planned->value,
            'price' => 999.999,
        ])
        ->call('create');

    $batch = Batch::where('code', 'ENG-CRAFTED')->first();

    expect($batch)->not->toBeNull()
        ->and($batch?->price)->toBeNull();
});

it('ignores a price smuggled into an edit payload', function () {
    $batch = Batch::factory()->create(['price' => null]);

    Livewire::actingAs(($this->makeUser)('admin'))
        ->test(EditBatch::class, ['record' => $batch->getKey()])
        ->fillForm(['price' => 999.999])
        ->call('save');

    expect($batch->fresh()?->price)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| No bulk actions
|--------------------------------------------------------------------------
*/

it('registers no bulk actions on the schedule table', function () {
    // Filament authorizes a bulk action once against the *Any policy method and
    // never consults the per-record one, so a bulk delete could not express
    // "unless this batch has enrolments" — the rule P1-T11 adds. BatchPolicy
    // defines no deleteAny(); this asserts the table offers nothing that would
    // consult it.
    $table = Livewire::actingAs(($this->makeUser)('super_admin'))
        ->test(ListBatches::class)
        ->instance()
        ->getTable();

    expect($table->getFlatBulkActions())->toBeEmpty()
        ->and($table->getToolbarActions())->toBeEmpty();
});

it('refuses deleteAny on the batch policy, through the panel', function () {
    /*
     * INVERTED BY P1-T15, security review finding 1.
     *
     * This test used to assert the OPPOSITE — that no deleteAny() existed — on
     * the stated grounds that "there is no *Any method for Filament to
     * authorize against, so it fails closed". That is true of Laravel's Gate
     * and false of Filament, which is the only one of the two a user reaches.
     *
     * get_authorization_response() consults the Gate only when
     * method_exists($policy, $action); otherwise, with strict authorization off
     * and no Gate::before callback, it returns Response::allow(). The absent
     * method was an OPEN door, and this test was certifying it as a closed one.
     *
     * Asserted through the resource rather than the Gate: Gate::allows() still
     * returns false for a missing method, so a gate-level assertion passes
     * whether or not the fix is present.
     */
    $this->actingAs(($this->makeUser)('super_admin'));

    expect(method_exists(BatchPolicy::class, 'deleteAny'))->toBeTrue()
        ->and(BatchResource::canDeleteAny())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Server-side authorization probes
|--------------------------------------------------------------------------
|
| mountAction() unmounts and returns null in three different cases —
| unresolvable name, disabled, and unauthorized — so a probe that only asserts
| "nothing happened" cannot tell a refusal from a typo. Every probe below first
| mounts the SAME action as an authorized actor and asserts the stack is not
| empty; without that control the refusal assertion proves nothing.
*/

it('refuses a table delete mounted directly by an actor without the delete grant', function () {
    $batch = Batch::factory()->create();

    // A table action only resolves with table context and the record key —
    // mountAction('delete') alone silently fails to resolve on a list page,
    // which would make this probe pass for the wrong reason.
    $context = ['table' => true, 'recordKey' => (string) $batch->getKey()];

    // Control: the action resolves for an actor who may delete.
    $control = Livewire::actingAs(($this->makeUser)('admin'))
        ->test(ListBatches::class);
    $control->call('mountAction', 'delete', [], $context);
    expect($control->get('mountedActions'))->not->toBeEmpty(
        'The table delete action did not resolve even for an admin — the probe below would prove nothing.'
    );

    $viewer = User::factory()->create(['is_active' => true]);
    $viewer->givePermissionTo('access_admin_panel', 'view_any_batch', 'view_batch');

    $component = Livewire::actingAs($viewer->fresh())->test(ListBatches::class);
    $component->call('mountAction', 'delete', [], $context);

    expect($component->get('mountedActions'))->toBeEmpty();

    // Call it anyway, the way a crafted client would after a failed mount.
    $component->call('callMountedAction');

    expect(Batch::whereKey($batch->getKey())->exists())->toBeTrue();
});

it('refuses an edit-page delete mounted directly by an editor who may not delete', function () {
    $editor = User::factory()->create(['is_active' => true]);
    $editor->givePermissionTo('access_admin_panel', 'view_any_batch', 'view_batch', 'update_batch');

    $batch = Batch::factory()->create();

    // Control first: prove the action resolves for someone allowed to use it.
    $control = Livewire::actingAs(($this->makeUser)('admin'))
        ->test(EditBatch::class, ['record' => $batch->getKey()]);
    $control->call('mountAction', 'delete');
    expect($control->get('mountedActions'))->not->toBeEmpty(
        'The delete action did not resolve even for an admin — the probe below would prove nothing.'
    );

    $component = Livewire::actingAs($editor->fresh())
        ->test(EditBatch::class, ['record' => $batch->getKey()]);

    $component->assertActionHidden('delete');

    $component->call('mountAction', 'delete');

    // Refused at mount: nothing is on the stack to execute.
    expect($component->get('mountedActions'))->toBeEmpty();

    $component->call('callMountedAction');

    expect(Batch::whereKey($batch->getKey())->exists())->toBeTrue();
});

it('lets an actor with delete but not update remove a batch from the table', function () {
    // delete_batch and update_batch are separate grants. Because
    // EditRecord::authorizeAccess() demands update_batch to open the edit page,
    // a delete action living only there would make delete_batch unreachable for
    // this actor. It is on the table row for exactly this case.
    $remover = User::factory()->create(['is_active' => true]);
    $remover->givePermissionTo('access_admin_panel', 'view_any_batch', 'view_batch', 'delete_batch');

    $batch = Batch::factory()->create();

    Livewire::actingAs($remover->fresh())
        ->test(ListBatches::class)
        ->callTableAction('delete', $batch);

    expect(Batch::count())->toBe(0);
});

it('lets an admin delete a batch from the edit page', function () {
    $batch = Batch::factory()->create();

    Livewire::actingAs(($this->makeUser)('admin'))
        ->test(EditBatch::class, ['record' => $batch->getKey()])
        ->callAction('delete');

    expect(Batch::count())->toBe(0)
        // The course it belonged to is untouched: batches are the instances,
        // the catalogue entry stays.
        ->and(Course::count())->toBe(1);
});

it('hides the create button from an actor who may not create', function () {
    // A plain link Action carries no authorization of its own, so ListBatches
    // checks canCreate() explicitly. Without that the button renders for
    // view-only staff, who then hit a 403 on the page behind it.
    Livewire::actingAs(($this->makeUser)('staff'))
        ->test(ListBatches::class)
        ->assertActionHidden('create');

    Livewire::actingAs(($this->makeUser)('admin'))
        ->test(ListBatches::class)
        ->assertActionVisible('create');
});

it('offers only active courses when scheduling a new batch', function () {
    // A retired course keeps its existing batches but must not receive new
    // ones — otherwise "retired" means nothing.
    $live = Course::factory()->create(['code' => 'ENG-B1']);
    $retired = Course::factory()->inactive()->create(['code' => 'ENG-OLD']);

    $options = Livewire::actingAs(($this->makeUser)('admin'))
        ->test(CreateBatch::class)
        ->instance()
        ->form
        ->getComponent('course_id')
        ?->getOptions();

    expect($options)->toBe([$live->getKey() => 'ENG-B1'])
        ->and($options)->not->toHaveKey($retired->getKey());
});
