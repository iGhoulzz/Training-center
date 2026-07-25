<?php

declare(strict_types=1);

use App\Domain\Staff\Models\StaffCertificate;
use App\Domain\Staff\Models\StaffProfile;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The certificate download route — the only way bytes leave the private disk.
 *
 * Authorization is per request through StaffCertificatePolicy::view(): a signed
 * URL would prove only that the link was not tampered with, never that its holder
 * is entitled to a national ID document. Every case is driven over real HTTP.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    Storage::fake('private');

    $this->profile = StaffProfile::factory()->create();

    // A certificate whose bytes really exist on the private disk.
    $this->bytes = 'national-id-scan-'.Str::random(12);
    $this->path = 'staff-certificates/'.Str::ulid()->toString().'.pdf';
    Storage::disk('private')->put($this->path, $this->bytes);

    $this->certificate = StaffCertificate::factory()->for($this->profile, 'staffProfile')->create([
        'disk' => 'private',
        'path' => $this->path,
        'original_filename' => 'my-diploma.pdf',
    ]);

    $this->url = route('staff.certificates.download', $this->certificate);

    $this->userWith = function (string ...$permissions): User {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(...$permissions);

        return $user->fresh();
    };
});

it('streams the file to an actor holding view_staff_certificate', function () {
    $response = $this->actingAs(($this->userWith)('view_staff_certificate'))->get($this->url);

    $response->assertOk();

    expect($response->streamedContent())->toBe($this->bytes)
        // The download header carries the stored original name, not the ULID path.
        ->and($response->headers->get('content-disposition'))->toContain('my-diploma.pdf');
});

it('refuses an authenticated actor without view_staff_certificate', function () {
    // Holds an unrelated permission, so this is a real authorization denial and
    // not merely an unauthenticated bounce.
    $this->actingAs(($this->userWith)('view_any_staff_profile'))
        ->get($this->url)
        ->assertForbidden();
});

it('refuses an unauthenticated request', function () {
    // The route carries no auth middleware; the controller itself refuses a
    // guest with 403 rather than redirecting to a login route that does not
    // exist in this application.
    $this->get($this->url)->assertForbidden();
});

it('refuses an actor holding view_staff_profile but not view_staff_certificate', function () {
    // The split-permission case: a job title and a scanned national ID are
    // different sensitivity levels, so reading the profile must not imply the
    // right to pull its documents.
    $this->actingAs(($this->userWith)('view_any_staff_profile', 'view_staff_profile'))
        ->get($this->url)
        ->assertForbidden();
});

it('allows an actor holding view_staff_certificate without update_staff_profile', function () {
    // The complement: certificates are readable without the ability to amend the
    // profile they hang off.
    $this->actingAs(($this->userWith)('view_staff_certificate'))
        ->get($this->url)
        ->assertOk();
});

it('404s when the stored path attempts to traverse out of the disk root', function () {
    // Every real path is a ULID, so this can only arrive by a manual DB edit or
    // a bad importer. The controller refuses it before touching the disk, so the
    // route can never read an arbitrary server file and stream it.
    $traversing = StaffCertificate::factory()->for($this->profile, 'staffProfile')->create([
        'disk' => 'private',
        'path' => '../../../../etc/passwd',
        'original_filename' => 'passwd',
    ]);

    // Authorized actor, so the 404 comes from the path guard, not authorization.
    $this->actingAs(($this->userWith)('view_staff_certificate'))
        ->get(route('staff.certificates.download', $traversing))
        ->assertNotFound();
});

it('404s when the row outlived its file', function () {
    $orphanRow = StaffCertificate::factory()->for($this->profile, 'staffProfile')->create([
        'disk' => 'private',
        'path' => 'staff-certificates/'.Str::ulid()->toString().'.pdf',
        'original_filename' => 'gone.pdf',
    ]);

    $this->actingAs(($this->userWith)('view_staff_certificate'))
        ->get(route('staff.certificates.download', $orphanRow))
        ->assertNotFound();
});
