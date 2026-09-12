<?php

declare(strict_types=1);

use App\Domain\Staff\Models\StaffCertificate;
use App\Domain\Staff\Models\StaffProfile;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

/**
 * Uploaded credentials, and the storage rule they exist to enforce.
 *
 * The expiry tests pin one deliberate decision: a null expires_on means the
 * credential does not expire. The file-storage tests pin the rule that is most
 * likely to be broken quietly later — these documents carry national ID numbers
 * and dates of birth, and a disk that becomes web-served hands them to anyone
 * with the URL, permanently.
 */
uses(RefreshDatabase::class);

/**
 * Do two directories overlap — identical, or one containing the other?
 *
 * Exact equality is not sufficient when comparing a private root against a
 * web-served one. A served directory that is an ANCESTOR of the private root
 * reaches every file beneath it; a served directory INSIDE the private root
 * exposes part of it. Either way the documents become reachable, so both
 * directions count as overlap.
 *
 * Both arguments must already be realpath()-resolved, so that a symlink or a
 * "..\" segment cannot make two names for one directory look distinct.
 */
function pathsOverlap(string $a, string $b): bool
{
    if ($a === '' || $b === '') {
        return false;
    }

    $a = rtrim($a, DIRECTORY_SEPARATOR);
    $b = rtrim($b, DIRECTORY_SEPARATOR);

    return $a === $b
        || str_starts_with($a, $b.DIRECTORY_SEPARATOR)
        || str_starts_with($b, $a.DIRECTORY_SEPARATOR);
}

it('lets one profile hold several certificates', function () {
    $profile = StaffProfile::factory()->create();

    StaffCertificate::factory()->count(3)->for($profile, 'staffProfile')->create();

    /*
     * DELIBERATELY A MULTI-ROW HYDRATION, AND THE EAGER LOAD IS WHAT CARRIES IT.
     *
     * P35-T04 turned Model::preventLazyLoading() on outside production, and
     * Builder::hydrate():498-501 arms the per-instance flag only when the result
     * carried more than one row.
     *
     * The first version of this fix read the relation off ->first(), which is a
     * one-row result: the model is never armed, so the assertion passed whether
     * the eager load was there or not. That removed the violation by disarming
     * the guard rather than by loading the relation — the eager load was
     * decorative, and deleting it would not have failed anything.
     *
     * Fetching all three keeps the guard live, so removing with('staffProfile')
     * below fails here with a LazyLoadingViolationException, which is what
     * T04's Done-when asks for.
     */
    $certificates = $profile->certificates()->with('staffProfile')->get();

    expect($profile->certificates()->count())->toBe(3)
        ->and($certificates)->toHaveCount(3)
        ->and($certificates->first()?->staffProfile->id)->toBe($profile->id);
});

it('deletes the certificates when the profile is deleted', function () {
    $profile = StaffProfile::factory()->create();
    StaffCertificate::factory()->count(2)->for($profile, 'staffProfile')->create();

    $profile->delete();

    expect(StaffCertificate::count())->toBe(0);
});

it('refuses to hard-delete the account rather than cascading through to the certificates', function () {
    /*
     * INVERTED BY P1-T15, domain-integrity finding 4.
     *
     * This test used to assert "two cascades in a row: users -> staff_profiles
     * -> staff_certificates" as though the chain were the feature. It was the
     * defect: a database cascade fires no Eloquent event, so no
     * pending_file_deletions receipt is written for any certificate removed that
     * way, and every scanned document is orphaned on disk — with the rows that
     * named those files already gone, so nothing can reconcile them afterwards.
     *
     * The first link is now restricted. The second is deliberately left
     * cascading: DeleteStaffProfileAction reads every certificate path before
     * triggering it. That cascade is safe precisely because an Action always
     * stands in front of it, which is what restricting the first link
     * guarantees.
     */
    $user = User::factory()->create();
    $profile = StaffProfile::factory()->for($user)->create();
    StaffCertificate::factory()->for($profile, 'staffProfile')->create();

    try {
        $user->forceDelete();
        $thrown = null;
    } catch (QueryException $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(QueryException::class)
        // 1451: "Cannot delete or update a parent row: a foreign key constraint
        // fails". The exact driver code, because any other QueryException would
        // prove nothing about the restriction.
        ->and($thrown->errorInfo[1] ?? null)->toBe(1451)
        ->and(StaffProfile::count())->toBe(1)
        ->and(StaffCertificate::count())->toBe(1);
});

it('records which disk holds the file, rather than assuming one', function () {
    $certificate = StaffCertificate::factory()->create();

    expect($certificate->fresh()?->disk)->toBe('private')
        ->and($certificate->fresh()?->path)->toStartWith('staff-certificates/');
});

it('casts the issue and expiry dates', function () {
    $certificate = StaffCertificate::factory()->create([
        'issued_on' => '2024-03-01',
        'expires_on' => '2027-03-01',
    ]);

    expect($certificate->fresh()?->issued_on?->toDateString())->toBe('2024-03-01')
        ->and($certificate->fresh()?->expires_on?->toDateString())->toBe('2027-03-01');
});

it('separates expired credentials from current ones', function () {
    $lapsed = StaffCertificate::factory()->expired()->create();
    $valid = StaffCertificate::factory()->create();
    $permanent = StaffCertificate::factory()->neverExpires()->create();

    expect(StaffCertificate::expired()->pluck('id')->all())->toBe([$lapsed->id])
        ->and(StaffCertificate::current()->pluck('id')->all())
        ->toEqualCanonicalizing([$valid->id, $permanent->id]);
});

it('treats a null expiry as a credential that never expires', function () {
    // A degree does not lapse, which is why the column is nullable. Reading
    // "no expiry recorded" as expired would flag every permanent qualification
    // in the centre.
    $permanent = StaffCertificate::factory()->neverExpires()->create();

    expect($permanent->expires_on)->toBeNull()
        ->and($permanent->isExpired())->toBeFalse()
        ->and(StaffCertificate::expired()->count())->toBe(0)
        ->and(StaffCertificate::current()->count())->toBe(1);
});

it('counts a credential as valid on its expiry date and expired the day after', function () {
    $expiringToday = StaffCertificate::factory()->expiringToday()->create();
    $expiredYesterday = StaffCertificate::factory()->create([
        'expires_on' => today()->subDay(),
    ]);

    expect($expiringToday->isExpired())->toBeFalse()
        ->and($expiredYesterday->isExpired())->toBeTrue()
        ->and(StaffCertificate::expired()->pluck('id')->all())->toBe([$expiredYesterday->id]);
});

it('keeps the current scope from leaking its or-condition into a surrounding filter', function () {
    // scopeCurrent groups its conditions. Without the group, the orWhere would
    // widen any filter applied alongside it and quietly return other people's
    // certificates.
    $mine = StaffProfile::factory()->create();
    $theirs = StaffProfile::factory()->create();

    StaffCertificate::factory()->for($mine, 'staffProfile')->neverExpires()->create();
    StaffCertificate::factory()->for($theirs, 'staffProfile')->neverExpires()->create();

    $found = StaffCertificate::query()
        ->where('staff_profile_id', $mine->id)
        ->current()
        ->get();

    expect($found)->toHaveCount(1)
        ->and($found->first()?->staff_profile_id)->toBe($mine->id);
});

/*
 * The storage rules. Spec section 6, "File storage".
 */

it('stores certificates on a disk that is private and outside the public directory', function () {
    /** @var array<string, mixed> $disk */
    $disk = config('filesystems.disks.private');

    expect($disk)->toBeArray()
        ->and($disk['driver'])->toBe('local')
        ->and($disk['visibility'])->toBe('private');

    $root = realpath((string) $disk['root']);
    $publicRoot = realpath(public_path());

    expect($root)->not->toBeFalse('The private disk root does not exist.')
        ->and(pathsOverlap((string) $root, (string) $publicRoot))->toBeFalse(
            'The private disk root overlaps public/, so the web server can reach it.'
        )
        ->and(pathsOverlap((string) $root, (string) realpath((string) config('filesystems.disks.public.root'))))
        ->toBeFalse('The private disk root overlaps the public disk root.');
});

it('does not share its root with any disk the framework serves over HTTP', function () {
    // Laravel's default `local` disk ships with serve => true, registering
    // GET /storage/{path} over its root. If the private disk shared that root,
    // the framework would hold a route capable of returning a staff
    // certificate, gated only by a URL signature — and a signature proves the
    // link was not tampered with, not that its holder is authorized.
    //
    // Asserting non-overlap against EVERY served disk, rather than naming
    // `local`, so a future served disk cannot quietly reintroduce the overlap.
    $privateRoot = realpath((string) config('filesystems.disks.private.root'));

    expect($privateRoot)->not->toBeFalse('The private disk root does not exist.');

    /** @var array<string, array<string, mixed>> $disks */
    $disks = config('filesystems.disks');

    foreach ($disks as $name => $disk) {
        if ($name === 'private' || ($disk['driver'] ?? null) !== 'local') {
            continue;
        }

        if (! ($disk['serve'] ?? false)) {
            continue;
        }

        $servedRoot = realpath((string) ($disk['root'] ?? ''));

        if ($servedRoot === false) {
            continue;
        }

        // Equality is not enough. A served disk rooted at an ANCESTOR of the
        // private root reaches every certificate beneath it, and a served disk
        // rooted INSIDE the private root exposes part of it. Both are overlap.
        expect(pathsOverlap((string) $privateRoot, $servedRoot))->toBeFalse(
            "The '{$name}' disk is served over HTTP and its root overlaps the private disk root."
        );
    }
});

it('gives the private disk no public url and no symlink into public', function () {
    /** @var array<string, mixed> $disk */
    $disk = config('filesystems.disks.private');

    // No 'url' key: nothing can hand out a base address for these files.
    // 'serve' false: Laravel registers no route for this disk (see
    // Illuminate\Filesystem\FilesystemServiceProvider::serveFiles).
    expect($disk)->not->toHaveKey('url')
        ->and($disk['serve'])->toBeFalse();

    // php artisan storage:link must never expose this disk. Read the root from
    // config rather than naming a path: an earlier version of this test checked
    // a hardcoded storage/app/private, which stopped matching the moment the
    // disk moved and would have passed while the real root sat symlinked into
    // public/.
    $privateRoot = realpath((string) $disk['root']);

    expect($privateRoot)->not->toBeFalse('The private disk root does not exist.');

    /** @var array<string, string> $links */
    $links = (array) config('filesystems.links');

    foreach ($links as $link => $target) {
        $resolvedTarget = realpath((string) $target);

        if ($resolvedTarget === false) {
            continue;
        }

        expect(pathsOverlap((string) $privateRoot, $resolvedTarget))->toBeFalse(
            "storage:link maps {$link} onto the private disk root, an ancestor of it, or a directory inside it."
        );
    }
});

it('refuses an unauthenticated web request for a stored certificate file', function () {
    // The behavioural proof, not just a config assertion: write a real file
    // where a certificate would live and try to fetch it over HTTP with no
    // credential and no signature.
    $path = 'staff-certificates/private-disk-probe.txt';
    $contents = 'national-id-0123456789';

    Storage::disk('private')->put($path, $contents);

    try {
        // The disk is configured with throw => false, so a failed write returns
        // false instead of raising. Without this the request below would be
        // refused for a file that was never there, and the test would pass
        // while proving nothing.
        expect(Storage::disk('private')->exists($path))->toBeTrue(
            'The probe file was not written, so the request below proves nothing.'
        );

        $response = $this->get('/storage/'.$path);

        expect($response->isSuccessful())->toBeFalse(
            'A certificate file was served over HTTP without authorization.'
        );

        $response->assertDontSee($contents);
    } finally {
        Storage::disk('private')->delete($path);
    }
});
