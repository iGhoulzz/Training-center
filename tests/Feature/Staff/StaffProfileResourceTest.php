<?php

declare(strict_types=1);

use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Domain\Staff\Filament\Resources\StaffProfileResource\Pages\ListStaffProfiles;
use App\Domain\Staff\Filament\Resources\StaffProfileResource\Pages\ViewStaffProfile;
use App\Domain\Staff\Filament\Resources\StaffProfileResource\RelationManagers\CertificatesRelationManager;
use App\Domain\Staff\Models\StaffCertificate;
use App\Domain\Staff\Models\StaffProfile;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * The staff register as it is actually reachable over HTTP and Livewire.
 *
 * The policy and lifecycle tests prove the rules; these prove the resource is
 * wired to them — no bulk bypass, and every delete refused on the server rather
 * than merely hidden. A refusal shown only by a missing button proves nothing.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    Storage::fake('private');

    $this->makeRole = function (string $role): User {
        $user = User::factory()->create(['is_active' => true]);
        app(SystemRoleWriter::class)->assignRoles($user, $role);

        return $user->fresh();
    };

    $this->userWith = function (string ...$permissions): User {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(...$permissions);

        return $user->fresh();
    };
});

/*
|--------------------------------------------------------------------------
| Page access
|--------------------------------------------------------------------------
*/

it('lets an admin reach the staff register', function () {
    $this->actingAs(($this->makeRole)('admin'))
        ->get('/admin/staff-profiles')
        ->assertSuccessful();
});

it('denies staff the staff register', function () {
    // staff holds no staff_profile permission at all — the Task 6 decision.
    $this->actingAs(($this->makeRole)('staff'))
        ->get('/admin/staff-profiles')
        ->assertForbidden();
});

it('denies a student the staff register', function () {
    $this->actingAs(($this->makeRole)('student'))
        ->get('/admin/staff-profiles')
        ->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| No bulk actions — the file-lifecycle bypass this resource must not offer
|--------------------------------------------------------------------------
*/

it('registers no bulk actions on the staff register table', function () {
    // A bulk delete authorizes once against deleteAny() and never runs
    // DeleteStaffProfileAction, so it would orphan every certificate file. The
    // table must offer none.
    $table = Livewire::actingAs(($this->makeRole)('super_admin'))
        ->test(ListStaffProfiles::class)
        ->instance()
        ->getTable();

    expect($table->getFlatBulkActions())->toBeEmpty()
        ->and($table->getToolbarActions())->toBeEmpty();
});

it('registers no bulk actions on the certificates relation manager', function () {
    $profile = StaffProfile::factory()->create();

    $table = Livewire::actingAs(($this->makeRole)('super_admin'))
        ->test(CertificatesRelationManager::class, [
            'ownerRecord' => $profile,
            'pageClass' => ViewStaffProfile::class,
        ])
        ->instance()
        ->getTable();

    expect($table->getFlatBulkActions())->toBeEmpty()
        ->and($table->getToolbarActions())->toBeEmpty();
});

/*
|--------------------------------------------------------------------------
| Server-side authorization probes (control first)
|--------------------------------------------------------------------------
|
| mountAction() unmounts and returns null for an unresolvable name, a disabled
| action, AND an unauthorized one, so a probe that only checks "nothing mounted"
| cannot tell a refusal from a typo. Each probe first mounts the SAME action as an
| authorized actor and asserts the stack is not empty; without that control the
| refusal proves nothing.
*/

it('refuses a profile delete mounted directly on the table by an actor who may not delete', function () {
    $profile = StaffProfile::factory()->create();
    $context = ['table' => true, 'recordKey' => (string) $profile->getKey()];

    // Control: the delete action resolves for an actor allowed to use it.
    $control = Livewire::actingAs(($this->makeRole)('admin'))->test(ListStaffProfiles::class);
    $control->call('mountAction', 'delete', [], $context);
    expect($control->get('mountedActions'))->not->toBeEmpty(
        'The table delete action did not resolve even for an admin — the probe below would prove nothing.'
    );

    // A view-only actor: the action refuses to mount, and calling it anyway is a
    // no-op.
    $viewer = ($this->userWith)('access_admin_panel', 'view_any_staff_profile', 'view_staff_profile');

    $component = Livewire::actingAs($viewer)->test(ListStaffProfiles::class);
    $component->call('mountAction', 'delete', [], $context);
    expect($component->get('mountedActions'))->toBeEmpty();

    $component->call('callMountedAction');

    expect(StaffProfile::whereKey($profile->getKey())->exists())->toBeTrue();
});

it('refuses a certificate delete mounted directly on the relation manager by an actor who may not delete', function () {
    $profile = StaffProfile::factory()->create();
    Storage::disk('private')->put('staff-certificates/probe.pdf', 'probe-bytes');
    $certificate = StaffCertificate::factory()->for($profile, 'staffProfile')->create([
        'disk' => 'private',
        'path' => 'staff-certificates/probe.pdf',
    ]);
    $context = ['table' => true, 'recordKey' => (string) $certificate->getKey()];

    $mount = fn (User $actor) => Livewire::actingAs($actor)->test(CertificatesRelationManager::class, [
        'ownerRecord' => $profile,
        'pageClass' => ViewStaffProfile::class,
    ]);

    // Control: an actor who may delete certificates resolves the action.
    $control = $mount(($this->makeRole)('admin'));
    $control->call('mountAction', 'delete', [], $context);
    expect($control->get('mountedActions'))->not->toBeEmpty(
        'The certificate delete action did not resolve even for an admin — the probe below would prove nothing.'
    );

    // An actor who can view but not delete certificates: refused at mount.
    $viewer = ($this->userWith)('access_admin_panel', 'view_any_staff_certificate', 'view_staff_certificate');

    $component = $mount($viewer);
    $component->call('mountAction', 'delete', [], $context);
    expect($component->get('mountedActions'))->toBeEmpty();

    $component->call('callMountedAction');

    expect(StaffCertificate::whereKey($certificate->getKey())->exists())->toBeTrue();
    Storage::disk('private')->assertExists($certificate->path);
});

/*
|--------------------------------------------------------------------------
| Split-permission profile delete, driven through the real component
|--------------------------------------------------------------------------
*/

it('refuses a profile delete through the table when the profile has certificates and the actor lacks the certificate grant', function () {
    $profile = StaffProfile::factory()->create();
    Storage::disk('private')->put('staff-certificates/kept.pdf', 'kept-bytes');
    $certificate = StaffCertificate::factory()->for($profile, 'staffProfile')->create([
        'disk' => 'private',
        'path' => 'staff-certificates/kept.pdf',
    ]);

    // Holds the profile-delete grant but NOT delete_staff_certificate, so the
    // Action refuses and its notification path leaves everything intact.
    $actor = ($this->userWith)(
        'access_admin_panel',
        'view_any_staff_profile',
        'view_staff_profile',
        'delete_staff_profile',
    );

    Livewire::actingAs($actor)
        ->test(ListStaffProfiles::class)
        ->callTableAction('delete', $profile);

    expect(StaffProfile::whereKey($profile->getKey())->exists())->toBeTrue()
        ->and(StaffCertificate::whereKey($certificate->getKey())->exists())->toBeTrue();
    Storage::disk('private')->assertExists($certificate->path);
});

it('lets an admin delete a profile with certificates through the table', function () {
    // The positive control: a full-access actor removes the profile and its
    // certificate rows through the same table action.
    $profile = StaffProfile::factory()->create();
    $certificate = StaffCertificate::factory()->for($profile, 'staffProfile')->create([
        'disk' => 'private',
        'path' => 'staff-certificates/removed.pdf',
    ]);

    Livewire::actingAs(($this->makeRole)('admin'))
        ->test(ListStaffProfiles::class)
        ->callTableAction('delete', $profile);

    expect(StaffProfile::whereKey($profile->getKey())->exists())->toBeFalse()
        ->and(StaffCertificate::whereKey($certificate->getKey())->exists())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| The certificate list is a separate grant from the profile
|--------------------------------------------------------------------------
*/

it('hides the certificates relation manager from an actor who may not view certificates', function () {
    $profile = StaffProfile::factory()->create();

    $profileOnly = ($this->userWith)('access_admin_panel', 'view_any_staff_profile', 'view_staff_profile');
    $certViewer = ($this->userWith)(
        'access_admin_panel',
        'view_any_staff_profile',
        'view_staff_profile',
        'view_any_staff_certificate',
        'view_staff_certificate',
    );

    $this->actingAs($profileOnly);
    expect(CertificatesRelationManager::canViewForRecord($profile, ViewStaffProfile::class))->toBeFalse();

    $this->actingAs($certViewer);
    expect(CertificatesRelationManager::canViewForRecord($profile, ViewStaffProfile::class))->toBeTrue();
});
