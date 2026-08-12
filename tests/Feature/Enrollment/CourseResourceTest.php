<?php

declare(strict_types=1);

use App\Domain\Enrollment\Filament\Resources\CourseResource;
use App\Domain\Enrollment\Filament\Resources\CourseResource\Pages\CreateCourse;
use App\Domain\Enrollment\Filament\Resources\CourseResource\Pages\EditCourse;
use App\Domain\Enrollment\Filament\Resources\CourseResource\Pages\ListCourses;
use App\Domain\Enrollment\Filament\Resources\CourseResource\Pages\ViewCourse;
use App\Domain\Enrollment\Models\Course;
use App\Domain\Enrollment\Policies\CoursePolicy;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

/**
 * The course catalogue as it is actually reachable over HTTP.
 *
 * The policy tests prove the rules; these prove the resource is wired to them.
 * Both are needed — a correct policy nobody consults denies nothing.
 *
 * The price assertions enforce the phase 2 ability boundary: admins see no
 * field and cannot smuggle its state, while super admins write through the real
 * Action-backed pages.
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

it('lets an admin reach the course catalogue', function () {
    $this->actingAs(($this->makeUser)('admin'))
        ->get('/admin/courses')
        ->assertSuccessful();
});

it('lets staff reach the course catalogue', function () {
    // Front-desk staff quote the catalogue to whoever walks in, so the read is
    // deliberately unscoped.
    $this->actingAs(($this->makeUser)('staff'))
        ->get('/admin/courses')
        ->assertSuccessful();
});

it('denies a student-role user the catalogue', function () {
    // Denied twice over: the student role holds neither access_admin_panel nor
    // any course permission.
    $this->actingAs(($this->makeUser)('student'))
        ->get('/admin/courses')
        ->assertForbidden();
});

it('lets staff open a course read only but refuses them create and edit', function () {
    // Staff hold view only on courses — narrower than on students, where they
    // also create. Defining what the centre sells is administrative work.
    $course = Course::factory()->create();
    $staff = ($this->makeUser)('staff');

    $this->actingAs($staff)->get("/admin/courses/{$course->getKey()}")->assertSuccessful();
    $this->actingAs($staff)->get('/admin/courses/create')->assertForbidden();
    $this->actingAs($staff)->get("/admin/courses/{$course->getKey()}/edit")->assertForbidden();
});

it('refuses a crafted edit submitted by staff, leaving the course unchanged', function () {
    // The edit page is already refused above; this proves the refusal is not
    // merely a hidden link by driving the component directly.
    $staff = ($this->makeUser)('staff');
    $course = Course::factory()->create(['name_en' => 'English B1']);

    Livewire::actingAs($staff)
        ->test(EditCourse::class, ['record' => $course->getKey()])
        ->assertForbidden();

    expect($course->fresh()?->name_en)->toBe('English B1');
});

it('refuses a crafted create submitted by staff', function () {
    $staff = ($this->makeUser)('staff');

    Livewire::actingAs($staff)
        ->test(CreateCourse::class)
        ->assertForbidden();

    expect(Course::count())->toBe(0);
});

it('gates each resource page on its own permission', function () {
    // The four pages are separate grants. An actor holding only view must not
    // reach create or edit, and each refusal must come from the page itself.
    $course = Course::factory()->create();

    $viewer = User::factory()->create(['is_active' => true]);
    $viewer->givePermissionTo('access_admin_panel', 'view_any_course', 'view_course');

    $creator = User::factory()->create(['is_active' => true]);
    $creator->givePermissionTo('access_admin_panel', 'view_any_course', 'view_course', 'create_course');

    $editor = User::factory()->create(['is_active' => true]);
    $editor->givePermissionTo('access_admin_panel', 'view_any_course', 'view_course', 'update_course');

    $key = $course->getKey();

    $this->actingAs($viewer->fresh())->get('/admin/courses')->assertSuccessful();
    $this->actingAs($viewer->fresh())->get("/admin/courses/{$key}")->assertSuccessful();
    $this->actingAs($viewer->fresh())->get('/admin/courses/create')->assertForbidden();
    $this->actingAs($viewer->fresh())->get("/admin/courses/{$key}/edit")->assertForbidden();

    $this->actingAs($creator->fresh())->get('/admin/courses/create')->assertSuccessful();
    $this->actingAs($creator->fresh())->get("/admin/courses/{$key}/edit")->assertForbidden();

    $this->actingAs($editor->fresh())->get("/admin/courses/{$key}/edit")->assertSuccessful();
    $this->actingAs($editor->fresh())->get('/admin/courses/create')->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Writes through the real pages
|--------------------------------------------------------------------------
*/

it('creates a course through the create page', function () {
    Livewire::actingAs(($this->makeUser)('admin'))
        ->test(CreateCourse::class)
        ->fillForm([
            'code' => 'ENG-B1',
            'name_en' => 'English B1',
            'total_hours' => 30,
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $course = Course::firstOrFail();

    expect($course->code)->toBe('ENG-B1')
        ->and($course->name_en)->toBe('English B1')
        ->and($course->total_hours)->toBe(30)
        ->and($course->is_active)->toBeTrue();
});

it('saves a real edit made by an admin', function () {
    // The refusal paths are covered above; this is the positive control that
    // proves the edit page is not simply broken for everyone.
    $course = Course::factory()->create([
        'code' => 'ENG-B1',
        'name_en' => 'English B1',
        'total_hours' => 30,
    ]);

    Livewire::actingAs(($this->makeUser)('admin'))
        ->test(EditCourse::class, ['record' => $course->getKey()])
        ->fillForm([
            'name_en' => 'English B1 (Evening)',
            'total_hours' => 36,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $course->refresh();

    expect($course->name_en)->toBe('English B1 (Evening)')
        ->and($course->total_hours)->toBe(36)
        ->and($course->code)->toBe('ENG-B1');
});

/*
|--------------------------------------------------------------------------
| Pricing ability boundary
|--------------------------------------------------------------------------
|
| Admins retain the phase 1 form with no price field. Super admins see the field,
| but generic Filament persistence never receives it: the page hook calls the
| self-authorizing pricing Action.
*/

it('exposes no price field on the create page', function () {
    Livewire::actingAs(($this->makeUser)('admin'))
        ->test(CreateCourse::class)
        // The control: a field that IS on the form, proving the assertion below
        // is not passing because the form failed to build at all.
        ->assertFormFieldExists('total_hours')
        ->assertFormFieldDoesNotExist('default_price');
});

it('exposes no price field on the edit page', function () {
    $course = Course::factory()->create();

    Livewire::actingAs(($this->makeUser)('admin'))
        ->test(EditCourse::class, ['record' => $course->getKey()])
        ->assertFormFieldExists('total_hours')
        ->assertFormFieldDoesNotExist('default_price');
});

it('exposes no price field on the view page', function () {
    $course = Course::factory()->create();

    Livewire::actingAs(($this->makeUser)('admin'))
        ->test(ViewCourse::class, ['record' => $course->getKey()])
        ->assertFormFieldExists('total_hours')
        ->assertFormFieldDoesNotExist('default_price');
});

it('lets a super admin set the default price when creating a course', function () {
    Livewire::actingAs(($this->makeUser)('super_admin'))
        ->test(CreateCourse::class)
        ->assertFormFieldExists('default_price')
        ->fillForm([
            'code' => 'ENG-PRICED',
            'name_en' => 'Priced Course',
            'total_hours' => 30,
            'default_price' => '425.750',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Course::where('code', 'ENG-PRICED')->value('default_price'))->toBe('425.750');
});

it('lets a super admin view and change a course default price', function () {
    $course = Course::factory()->create(['default_price' => '100.000']);
    $superAdmin = ($this->makeUser)('super_admin');

    Livewire::actingAs($superAdmin)
        ->test(ViewCourse::class, ['record' => $course->getKey()])
        ->assertFormFieldExists('default_price');

    Livewire::actingAs($superAdmin)
        ->test(EditCourse::class, ['record' => $course->getKey()])
        ->assertFormFieldExists('default_price')
        ->fillForm(['default_price' => '125.500'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($course->fresh()?->default_price)->toBe('125.500');
});

it('exposes no price column in the catalogue table', function () {
    Course::factory()->create();

    Livewire::actingAs(($this->makeUser)('admin'))
        ->test(ListCourses::class)
        ->assertTableColumnExists('total_hours')
        ->assertTableColumnDoesNotExist('default_price');
});

it('ignores a default_price smuggled into the create payload', function () {
    // This submits the hidden state the way a hand-written Livewire payload
    // would. The ability check must happen before the page reads raw state.
    Livewire::actingAs(($this->makeUser)('admin'))
        ->test(CreateCourse::class)
        ->fillForm([
            'code' => 'ENG-CRAFTED',
            'name_en' => 'Crafted Payload',
            'total_hours' => 30,
            'default_price' => 999.999,
        ])
        ->call('create');

    $course = Course::where('code', 'ENG-CRAFTED')->first();

    expect($course)->not->toBeNull()
        ->and((string) $course?->default_price)->toBe('0.000');
});

it('ignores a default_price smuggled into an edit payload', function () {
    $course = Course::factory()->create(['default_price' => 0]);

    Livewire::actingAs(($this->makeUser)('admin'))
        ->test(EditCourse::class, ['record' => $course->getKey()])
        ->fillForm(['default_price' => 999.999])
        ->call('save');

    expect((string) $course->fresh()?->default_price)->toBe('0.000');
});

/*
|--------------------------------------------------------------------------
| No bulk actions
|--------------------------------------------------------------------------
*/

it('registers no bulk actions on the catalogue table', function () {
    // Filament authorizes a bulk action once against the *Any policy method and
    // never consults the per-record one, so a bulk delete could not express
    // "unless this course has batches". CoursePolicy::deleteAny() refuses
    // outright; this asserts the table offers nothing that would consult it.
    $table = Livewire::actingAs(($this->makeUser)('super_admin'))
        ->test(ListCourses::class)
        ->instance()
        ->getTable();

    expect($table->getFlatBulkActions())->toBeEmpty()
        ->and($table->getToolbarActions())->toBeEmpty();
});

it('refuses deleteAny on the course policy, through the panel', function () {
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

    expect(method_exists(CoursePolicy::class, 'deleteAny'))->toBeTrue()
        ->and(CourseResource::canDeleteAny())->toBeFalse();
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
    $course = Course::factory()->create();

    // A table action only resolves with table context and the record key —
    // mountAction('delete') alone silently fails to resolve on a list page,
    // which would make this probe pass for the wrong reason.
    $context = ['table' => true, 'recordKey' => (string) $course->getKey()];

    // Control: the action resolves for an actor who may delete.
    $control = Livewire::actingAs(($this->makeUser)('admin'))
        ->test(ListCourses::class);
    $control->call('mountAction', 'delete', [], $context);
    expect($control->get('mountedActions'))->not->toBeEmpty(
        'The table delete action did not resolve even for an admin — the probe below would prove nothing.'
    );

    $viewer = User::factory()->create(['is_active' => true]);
    $viewer->givePermissionTo('access_admin_panel', 'view_any_course', 'view_course');

    $component = Livewire::actingAs($viewer->fresh())->test(ListCourses::class);
    $component->call('mountAction', 'delete', [], $context);

    expect($component->get('mountedActions'))->toBeEmpty();

    // Call it anyway, the way a crafted client would after a failed mount.
    $component->call('callMountedAction');

    expect(Course::whereKey($course->getKey())->exists())->toBeTrue();
});

it('refuses an edit-page delete mounted directly by an editor who may not delete', function () {
    $editor = User::factory()->create(['is_active' => true]);
    $editor->givePermissionTo('access_admin_panel', 'view_any_course', 'view_course', 'update_course');

    $course = Course::factory()->create();

    // Control first: prove the action resolves for someone allowed to use it.
    $control = Livewire::actingAs(($this->makeUser)('admin'))
        ->test(EditCourse::class, ['record' => $course->getKey()]);
    $control->call('mountAction', 'delete');
    expect($control->get('mountedActions'))->not->toBeEmpty(
        'The delete action did not resolve even for an admin — the probe below would prove nothing.'
    );

    $component = Livewire::actingAs($editor->fresh())
        ->test(EditCourse::class, ['record' => $course->getKey()]);

    $component->assertActionHidden('delete');

    $component->call('mountAction', 'delete');

    // Refused at mount: nothing is on the stack to execute.
    expect($component->get('mountedActions'))->toBeEmpty();

    $component->call('callMountedAction');

    expect(Course::whereKey($course->getKey())->exists())->toBeTrue();
});

it('lets an actor with delete but not update remove a course from the table', function () {
    // delete_course and update_course are separate grants. Because
    // EditRecord::authorizeAccess() demands update_course to open the edit page,
    // a delete action living only there would make delete_course unreachable for
    // this actor. It is on the table row for exactly this case.
    $remover = User::factory()->create(['is_active' => true]);
    $remover->givePermissionTo('access_admin_panel', 'view_any_course', 'view_course', 'delete_course');

    $course = Course::factory()->create();

    Livewire::actingAs($remover->fresh())
        ->test(ListCourses::class)
        ->callTableAction('delete', $course);

    expect(Course::count())->toBe(0);
});

it('lets an admin delete a course from the edit page', function () {
    $course = Course::factory()->create();

    Livewire::actingAs(($this->makeUser)('admin'))
        ->test(EditCourse::class, ['record' => $course->getKey()])
        ->callAction('delete');

    // A course hard deletes: unlike a student, it carries no personal history,
    // and a course with batches cannot reach this point at all — the foreign key
    // refuses it. BatchTest asserts that refusal.
    expect(Course::count())->toBe(0);
});

it('hides the create button from an actor who may not create', function () {
    // A plain link Action carries no authorization of its own, so ListCourses
    // checks canCreate() explicitly. Without that the button renders for
    // view-only staff, who then hit a 403 on the page behind it.
    Livewire::actingAs(($this->makeUser)('staff'))
        ->test(ListCourses::class)
        ->assertActionHidden('create');

    Livewire::actingAs(($this->makeUser)('admin'))
        ->test(ListCourses::class)
        ->assertActionVisible('create');
});
