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
        ->and($response->headers->get('content-disposition'))->toContain('my-diploma.pdf')
        ->and($response->headers->get('cache-control'))->toContain('private')
        ->and($response->headers->get('cache-control'))->toContain('no-store');
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

it('refuses an inactive actor even when their retained permission allows viewing', function () {
    $inactive = ($this->userWith)('view_staff_certificate');
    $inactive->update(['is_active' => false]);

    $this->actingAs($inactive->fresh())
        ->get($this->url)
        ->assertForbidden();
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

it('404s when the stored disk is not one this route may serve from', function () {
    /*
     * P1-T15, domain-integrity finding 5.
     *
     * The path guard above proves the path cannot escape THE DISK ROOT. Which
     * root that is came from the row's own `disk` column, so a row naming a
     * different disk moved the whole containment boundary somewhere else — and
     * `staff-certificates/x.pdf` is a perfectly contained path under every root
     * there is. The guard was doing real work against a value that decided what
     * it was guarding.
     *
     * UploadStaffCertificateAction sets `disk` server-side and never from
     * request input, so this arrives the same way a traversing path would: a
     * manual database edit, a restored backup, a future importer. The consequence
     * is different in kind, though — a traversal is refused by Flysystem as a
     * second line of defence, whereas a swapped disk is a completely legitimate
     * read of a completely different tree, and nothing downstream objects.
     */
    $canary = 'bytes-from-a-disk-this-route-must-not-serve';
    $strayPath = 'staff-certificates/'.Str::ulid()->toString().'.pdf';

    Storage::fake('local');
    Storage::disk('local')->put($strayPath, $canary);

    $stray = StaffCertificate::factory()->for($this->profile, 'staffProfile')->create([
        'disk' => 'local',
        'path' => $strayPath,
        'original_filename' => 'elsewhere.pdf',
    ]);

    // Authorized actor, so the refusal comes from the disk guard rather than
    // from authorization.
    $response = $this->actingAs(($this->userWith)('view_staff_certificate'))
        ->get(route('staff.certificates.download', $stray));

    $response->assertNotFound();

    /*
     * The status alone is not the assertion. A refusal that still wrote the
     * bytes would satisfy a status check and leak the file anyway.
     *
     * getContent() rather than streamedContent(): a refusal is an ordinary
     * response, and streamedContent() fails outright on one. That asymmetry is
     * itself part of the check — reaching the streaming branch at all would show
     * up here as "the response is not a streamed response" inverted.
     */
    expect($response->getContent())->not->toContain($canary);

    // And the bytes are still sitting where they were, so the refusal is a
    // refusal to SERVE rather than some side effect that moved them.
    Storage::disk('local')->assertExists($strayPath);
});

it('404s when a certificate row points into another feature\'s namespace on the same disk', function () {
    /*
     * P1-T15, round-two review finding: cross-namespace disclosure.
     *
     * The disk guard fixed WHICH ROOT the path resolves against. It said nothing
     * about WHERE UNDER THAT ROOT, and the private disk is shared — staff photos
     * live beside certificates today, and the pending_file_deletions migration
     * states that phase 2 receipts and phase 3 student certificates will reuse
     * the same disk.
     *
     * So a certificate row naming `staff-photos/{ULID}.png` passed the
     * containment check and the disk check, and the route then authorized the
     * CERTIFICATE and streamed the PHOTO. An actor holding view_staff_certificate
     * and no profile permission at all could read it — and once the phase 2 and 3
     * namespaces exist, the same hole reaches a financial receipt or a student's
     * document across a permission boundary that was never consulted.
     *
     * StaffProfilePhotoController has always required the exact generated shape
     * for precisely this reason. The asymmetry was the bug.
     */
    $canary = 'staff-photo-bytes-a-certificate-grant-must-not-reach';
    $photoPath = 'staff-photos/'.Str::ulid()->toString().'.png';
    Storage::disk('private')->put($photoPath, $canary);

    $crossed = StaffCertificate::factory()->for($this->profile, 'staffProfile')->create([
        'disk' => 'private',
        'path' => $photoPath,
        'original_filename' => 'not-really-a-certificate.png',
    ]);

    // A certificate grant and nothing else. Asserted rather than assumed: if this
    // actor happened to hold a profile permission the test would prove nothing
    // about the boundary it exists to check.
    $actor = ($this->userWith)('view_staff_certificate');
    expect($actor->can('view_any_staff_profile'))->toBeFalse()
        ->and($actor->can('view_staff_profile'))->toBeFalse();

    $response = $this->actingAs($actor)->get(route('staff.certificates.download', $crossed));

    $response->assertNotFound();

    expect($response->getContent())->not->toContain($canary);

    // The photo is untouched — this is a refusal to serve, not a side effect.
    Storage::disk('private')->assertExists($photoPath);
});

it('404s on a stored path that is not the exact generated certificate shape', function (string $path) {
    /*
     * The shape is the boundary, so every way of missing it is refused: a
     * directory the feature does not own, a nested path inside one it does, a
     * name that is not a ULID, and an extension the upload Action never
     * produces. Each is written out because each fails a different clause, and
     * a single sample would let the others rot.
     */
    Storage::disk('private')->put($path, 'bytes-behind-a-malformed-row');

    $malformed = StaffCertificate::factory()->for($this->profile, 'staffProfile')->create([
        'disk' => 'private',
        'path' => $path,
        'original_filename' => 'whatever.pdf',
    ]);

    $this->actingAs(($this->userWith)('view_staff_certificate'))
        ->get(route('staff.certificates.download', $malformed))
        ->assertNotFound();
})->with([
    'a sibling namespace on the same disk' => 'staff-photos/01J0000000000000000000000A.png',
    'nested below the certificate directory' => 'staff-certificates/nested/01J0000000000000000000000A.pdf',
    'a bare filename with no directory' => '01J0000000000000000000000A.pdf',
    'a name that is not a ULID' => 'staff-certificates/payroll-export.pdf',
    'an extension the upload Action never writes' => 'staff-certificates/01J0000000000000000000000A.exe',
    /*
     * ADDED BECAUSE MUTATION TESTING FOUND THE SET INCOMPLETE.
     *
     * Deleting the two-segment requirement broke nothing, and the reason was
     * this gap rather than a redundant check: every other malformed sample
     * happens to put something non-ULID in the SECOND segment, so the shape
     * regex catches it and the count never gets a say. A valid certificate name
     * with a segment after it passes the directory check and the shape check on
     * segment two, and the bytes really are reachable when a directory of that
     * name exists on disk.
     */
    'a trailing segment after a valid certificate name' => 'staff-certificates/01J0000000000000000000000A.pdf/extra',
]);

it('serves every extension the upload Action does produce', function (string $extension) {
    /*
     * The control for the shape check, and it is a dataset rather than one case
     * because a regex that accidentally admitted only PDFs would pass a single
     * sample while silently 404ing every image credential already on disk.
     */
    $path = 'staff-certificates/'.Str::ulid()->toString().'.'.$extension;
    $bytes = 'credential-bytes-'.$extension;
    Storage::disk('private')->put($path, $bytes);

    $certificate = StaffCertificate::factory()->for($this->profile, 'staffProfile')->create([
        'disk' => 'private',
        'path' => $path,
        'original_filename' => 'credential.'.$extension,
    ]);

    $response = $this->actingAs(($this->userWith)('view_staff_certificate'))
        ->get(route('staff.certificates.download', $certificate));

    $response->assertOk();
    expect($response->streamedContent())->toBe($bytes);
})->with(['pdf', 'jpg', 'png', 'webp']);

it('serves a certificate whose stored disk is the one the feature writes to', function () {
    /*
     * The control for the refusal above. Without it, a disk guard that refused
     * everything — or a route that had simply stopped working — would look
     * identical to a correct one.
     *
     * The main streaming test above covers the same ground, but this states the
     * pairing explicitly so the two cannot drift apart if that test is ever
     * rewritten for another reason.
     */
    $response = $this->actingAs(($this->userWith)('view_staff_certificate'))->get($this->url);

    $response->assertOk();

    expect($response->streamedContent())->toBe($this->bytes);
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
