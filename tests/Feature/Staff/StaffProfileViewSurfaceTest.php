<?php

declare(strict_types=1);

use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Domain\Staff\Filament\Resources\StaffProfileResource;
use App\Domain\Staff\Models\StaffProfile;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The staff profile view page discloses one profile, not the roster (P1-T15)
|--------------------------------------------------------------------------
|
| ViewStaffProfile declared no schema and StaffProfileResource had no infolist(),
| so Filament fell back to form() — whose first field is
| Select::make('user_id')->options(User::query()->pluck('name', 'id')). Every
| account name in the system was rendered as options to anyone who could open the
| page.
|
| WHY NO SEEDED ROLE COULD SHOW THIS. Every seeded role holding
| view_staff_profile also holds view_any_user, so the disclosure was invisible:
| the viewer was always someone already entitled to the list. The actor below is
| synthetic and holds profile read with NO user permission — the shape the next
| role somebody adds might have.
*/

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->system = app(SystemRoleWriter::class);

    // The synthetic actor. Deliberately not a seeded role.
    $role = Role::create(['name' => 'profile_reader', 'guard_name' => 'web']);
    $role->givePermissionTo(['view_any_staff_profile', 'view_staff_profile', 'access_admin_panel']);

    $this->viewer = User::factory()->create(['is_active' => true, 'name' => 'The Viewer']);
    $this->system->assignRoles($this->viewer, 'profile_reader');
    $this->viewer->refresh();

    $this->owner = User::factory()->create(['is_active' => true, 'name' => 'Profile Owner']);
    $this->profile = StaffProfile::factory()->for($this->owner)->create([
        'job_title' => 'Lead Instructor',
    ]);

    // Two accounts the viewer has no business seeing. Distinctive enough that a
    // substring match cannot pass by coincidence.
    $this->stranger = User::factory()->create(['is_active' => true, 'name' => 'Zzz Unrelated Stranger']);
    $this->otherStranger = User::factory()->create(['is_active' => true, 'name' => 'Qqq Second Stranger']);

    $this->viewProfile = fn () => $this->actingAs($this->viewer)
        ->get(StaffProfileResource::getUrl('view', ['record' => $this->profile]));
});

it('renders the requested profile', function () {
    ($this->viewProfile)()->assertSuccessful();
});

it('renders the profile own permitted information', function () {
    $response = ($this->viewProfile)();

    $response->assertSee('Lead Instructor')
        // The owner's name is the one account this viewer asked about.
        ->assertSee('Profile Owner');
});

it('never renders an unrelated account name', function () {
    $response = ($this->viewProfile)();

    // The heart of the finding. Before the view schema existed, both of these
    // appeared as <option> values.
    $response->assertDontSee('Zzz Unrelated Stranger')
        ->assertDontSee('Qqq Second Stranger');
});

it('offers no account-selection control and no roster options', function () {
    $html = (string) ($this->viewProfile)()->getContent();

    // Not merely "the strangers are absent" — no selection control at all, so a
    // future account cannot reappear through it.
    expect($html)->not->toContain('user_id')
        ->and(substr_count($html, '<option'))->toBe(0);
});

it('does not require view_user to read a staff profile', function () {
    // The permission boundary the fix must not quietly move: profile read and
    // account read stay separate grants.
    expect($this->viewer->can('view_any_user'))->toBeFalse()
        ->and($this->viewer->can('view_user'))->toBeFalse();

    ($this->viewProfile)()->assertSuccessful();
});

/*
|--------------------------------------------------------------------------
| The structural guarantee
|--------------------------------------------------------------------------
*/

it('defines a view schema that is not the editable form', function () {
    /*
     * Asserted structurally as well as behaviourally. The behavioural tests
     * above would also pass if someone hid the Select conditionally — leaving
     * the roster query in the render path, one condition away from disclosure,
     * and re-coupling view to edit so every future form field is exposed here by
     * default.
     */
    $components = StaffProfileResource::infolist(app(Schema::class))->getComponents();

    $selects = collect($components)
        ->filter(fn ($component): bool => $component instanceof Select)
        ->count();

    // Named rather than counted. An earlier version compared component COUNTS
    // between the two schemas, which happened to be equal — a proxy that would
    // have gone on passing while the form was handed back verbatim.
    $names = collect($components)
        ->map(fn ($component): string => method_exists($component, 'getName') ? (string) $component->getName() : '')
        ->all();

    expect($selects)->toBe(0, 'The view schema must contain no Select: a view page never chooses an owner.')
        ->and($names)->not->toContain('user_id')
        // The owner is read through the profile's own relation, one name only.
        ->and($names)->toContain('user.name');
});
