<?php

declare(strict_types=1);

use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Domain\Staff\Enums\EmploymentType;
use App\Domain\Staff\Filament\Resources\StaffProfileResource\Pages\CreateStaffProfile;
use App\Domain\Staff\Filament\Resources\StaffProfileResource\Pages\EditStaffProfile;
use App\Domain\Staff\Filament\Resources\StaffProfileResource\Pages\ListStaffProfiles;
use App\Domain\Staff\Filament\Resources\StaffProfileResource\Pages\ViewStaffProfile;
use App\Domain\Staff\Filament\Resources\StaffProfileResource\RelationManagers\CertificatesRelationManager;
use App\Domain\Staff\Models\StaffCertificate;
use App\Domain\Staff\Models\StaffProfile;
use App\Domain\Staff\Services\FileLifecycleService;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Tables\Columns\ImageColumn;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

afterEach(function () {
    DB::disconnect(FileLifecycleService::compensationConnectionName());
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
| Profile form boundaries and avatar rendering
|--------------------------------------------------------------------------
*/

it('keeps profile ownership immutable on a crafted edit while saving legitimate fields', function () {
    $profile = StaffProfile::factory()->create(['job_title' => 'Original title']);
    $originalUserId = $profile->user_id;
    $otherUser = User::factory()->create();

    Livewire::actingAs(($this->makeRole)('admin'))
        ->test(EditStaffProfile::class, ['record' => $profile->getKey()])
        ->fillForm([
            'user_id' => $otherUser->getKey(),
            'job_title' => 'Updated title',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $profile->refresh();

    expect($profile->user_id)->toBe($originalUserId)
        ->and($profile->job_title)->toBe('Updated title');
});

it('still persists profile ownership on creation', function () {
    $staffUser = User::factory()->create();

    Livewire::actingAs(($this->makeRole)('admin'))
        ->test(CreateStaffProfile::class)
        ->fillForm([
            'user_id' => $staffUser->getKey(),
            'job_title' => 'New instructor',
            'employment_type' => EmploymentType::Instructor->value,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(StaffProfile::where('user_id', $staffUser->getKey())->exists())->toBeTrue();
});

it('writes a real profile photo through the edit page save hook', function () {
    $profile = StaffProfile::factory()->create(['profile_photo_path' => null]);

    Livewire::actingAs(($this->makeRole)('admin'))
        ->test(EditStaffProfile::class, ['record' => $profile->getKey()])
        ->fillForm([
            'profile_photo' => livewirePngUpload('avatar.png'),
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $path = $profile->fresh()->profile_photo_path;

    expect($path)->toBeString()
        ->and($path)->toStartWith('staff-photos/');
    Storage::disk('private')->assertExists($path);
});

/*
|--------------------------------------------------------------------------
| A full or read-only disk (P1-T15, domain-integrity finding 3)
|--------------------------------------------------------------------------
|
| The private disk is configured with throw => false, so the Actions turn a
| false return into FileStorageException rather than pretending the write
| succeeded. Nothing caught it, so a full disk reached the administrator as an
| unexplained 500 mid-save.
|
| Both tests assert the SAVE OUTCOME rather than the notification text. A polite
| notification with a committed half-save behind it is the actual harm, and it is
| the half a wording change must not be able to hide.
*/

/**
 * Make every write to the private disk report failure, as a full disk does.
 *
 * Storage::set() replaces that ONE disk. Storage::shouldReceive('disk') would
 * mock the whole facade, and Livewire's own temporary-upload disk then arrives
 * at a mock with no expectations — the failure is unrelated to the code under
 * test and reads as if the guard misfired.
 */
function failThePrivateDisk(): void
{
    $failing = Mockery::mock(Filesystem::class);
    $failing->shouldReceive('putFileAs')->andReturn(false);
    // The compensation receipt written before the bytes is purged on the sync
    // queue, and that cleanup must still be able to run.
    $failing->shouldReceive('delete')->andReturn(true);
    $failing->shouldReceive('exists')->andReturn(false);

    Storage::set('private', $failing);
}

it('rolls the whole profile save back when the disk cannot take the photo', function () {
    /*
     * THE ROLLBACK IS THE ASSERTION.
     *
     * Without it the job title commits while the photo silently does not, and
     * the administrator is left looking at a record they believe they updated in
     * full. EditStaffProfile sets $hasDatabaseTransactions = true and the refusal
     * throws Halt::rollBackDatabaseTransaction(), which is what unwinds it.
     */
    $profile = StaffProfile::factory()->create([
        'profile_photo_path' => null,
        'job_title' => 'Original title',
    ]);

    failThePrivateDisk();

    Livewire::actingAs(($this->makeRole)('admin'))
        ->test(EditStaffProfile::class, ['record' => $profile->getKey()])
        ->fillForm([
            'job_title' => 'Edited title',
            'profile_photo' => livewirePngUpload('avatar.png'),
        ])
        ->call('save')
        // The administrator is told why, not merely left on an unchanged form.
        ->assertNotified(__('staff.storage_unavailable'));

    $profile = $profile->fresh();

    expect($profile->profile_photo_path)->toBeNull()
        ->and($profile->job_title)->toBe(
            'Original title',
            'The attribute write survived a refused photo, so the save committed in part and '
            .'the record now disagrees with what the administrator was shown.',
        );
});

it('records no certificate when the disk cannot take the uploaded file', function () {
    // The upload path has no half-save to undo — the Action writes no row when
    // the bytes fail — so what matters is that no phantom certificate is
    // recorded for a document that was never stored.
    $profile = StaffProfile::factory()->create();

    failThePrivateDisk();

    Livewire::actingAs(($this->makeRole)('admin'))
        ->test(CertificatesRelationManager::class, [
            'ownerRecord' => $profile,
            'pageClass' => ViewStaffProfile::class,
        ])
        ->callTableAction('create', null, [
            'title' => 'Credential that never landed',
            'issued_on' => '2024-01-01',
            'expires_on' => '2030-01-01',
            'certificate_file' => livewirePngUpload('credential.png'),
        ])
        /*
         * The Halt and the notification, pinned rather than described.
         *
         * The row count below is the harm, but on its own it also passes for an
         * action that failed silently and closed the modal — losing the metadata
         * the administrator had typed and telling them nothing. These two assert
         * the outcome the comment in the relation manager actually claims.
         */
        ->assertTableActionHalted('create')
        ->assertNotified(__('staff.storage_unavailable'));

    expect(StaffCertificate::count())->toBe(
        0,
        'A certificate row exists for bytes the disk refused, so the register lists a '
        .'document that was never stored.',
    );
});

it('renders an authorized photo route and falls back to initials when no photo exists', function () {
    $withPhoto = StaffProfile::factory()->create([
        'profile_photo_path' => 'staff-photos/avatar.png',
    ]);
    $withoutPhoto = StaffProfile::factory()
        ->for(User::factory()->state(['name' => 'Amal Ibrahim']), 'user')
        ->create(['profile_photo_path' => null]);

    Livewire::actingAs(($this->makeRole)('admin'))
        ->test(ListStaffProfiles::class)
        ->assertTableColumnStateSet(
            'profile_photo_path',
            route('staff.profiles.photo', $withPhoto),
            $withPhoto,
        )
        ->assertTableColumnStateSet('profile_photo_path', null, $withoutPhoto)
        ->assertTableColumnExists(
            'profile_photo_path',
            fn (ImageColumn $column): bool => $column->getPlaceholder() === $withoutPhoto->initials()
                && $column->isCircular(),
            $withoutPhoto,
        );
});

/*
|--------------------------------------------------------------------------
| No bulk actions
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
| Split certificate permissions, driven through the real component
|--------------------------------------------------------------------------
*/

it('lets a create-only certificate grant upload from the view page but refuses deletion', function () {
    $profile = StaffProfile::factory()->create();
    $actor = ($this->userWith)(
        'access_admin_panel',
        'view_any_staff_profile',
        'view_staff_profile',
        'view_any_staff_certificate',
        'view_staff_certificate',
        'create_staff_certificate',
    );

    $mount = fn () => Livewire::actingAs($actor)->test(CertificatesRelationManager::class, [
        'ownerRecord' => $profile,
        'pageClass' => ViewStaffProfile::class,
    ]);

    $mount()
        ->callTableAction('create', null, [
            'title' => 'Create-only credential',
            'issued_on' => '2024-01-01',
            'expires_on' => '2030-01-01',
            'certificate_file' => livewirePngUpload('credential.png'),
        ])
        ->assertHasNoTableActionErrors();

    $certificate = StaffCertificate::sole();
    Storage::disk('private')->assertExists($certificate->path);

    // Drive the raw server mount: a helper that first asserts action visibility
    // would prove only that the test helper refused, not the Livewire endpoint.
    $context = ['table' => true, 'recordKey' => (string) $certificate->getKey()];
    $component = $mount();
    $component->call('mountAction', 'delete', [], $context);
    expect($component->get('mountedActions'))->toBeEmpty();
    $component->call('callMountedAction');

    expect(StaffCertificate::whereKey($certificate->getKey())->exists())->toBeTrue();
    Storage::disk('private')->assertExists($certificate->path);
});

it('lets a delete-only certificate grant remove a certificate from the view page', function () {
    $profile = StaffProfile::factory()->create();
    $path = 'staff-certificates/delete-only.pdf';
    Storage::disk('private')->put($path, 'private-bytes');
    $certificate = StaffCertificate::factory()->for($profile, 'staffProfile')->create([
        'disk' => 'private',
        'path' => $path,
    ]);

    $actor = ($this->userWith)(
        'access_admin_panel',
        'view_any_staff_profile',
        'view_staff_profile',
        'view_any_staff_certificate',
        'view_staff_certificate',
        'delete_staff_certificate',
    );

    Livewire::actingAs($actor)
        ->test(CertificatesRelationManager::class, [
            'ownerRecord' => $profile,
            'pageClass' => ViewStaffProfile::class,
        ])
        ->callTableAction('delete', $certificate);

    expect(StaffCertificate::whereKey($certificate->getKey())->exists())->toBeFalse();
    Storage::disk('private')->assertMissing($path);
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
