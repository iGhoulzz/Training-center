<?php

declare(strict_types=1);

use App\Domain\Staff\Actions\DeleteStaffProfileAction;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Domain\Staff\Enums\EmploymentType;
use App\Domain\Staff\Models\PendingFileDeletion;
use App\Domain\Staff\Models\StaffCertificate;
use App\Domain\Staff\Models\StaffProfile;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The staff profile is the employment record beside a login account. These
 * tests pin the parts of it that are structural — the 1:1 constraint, the
 * referential actions, the enum cast — because each is enforced by the database
 * and would otherwise only be discovered in production.
 */
uses(RefreshDatabase::class);

it('links one profile to one user in both directions', function () {
    $user = User::factory()->create();
    $profile = StaffProfile::factory()->for($user)->create();

    expect($user->fresh()?->staffProfile?->id)->toBe($profile->id)
        ->and($profile->user->id)->toBe($user->id);
});

it('rejects a second profile for the same user', function () {
    $user = User::factory()->create();

    StaffProfile::factory()->for($user)->create();
    StaffProfile::factory()->for($user)->create();
})->throws(UniqueConstraintViolationException::class);

it('casts employment type to an enum', function () {
    $profile = StaffProfile::factory()->create([
        'employment_type' => EmploymentType::Instructor,
    ]);

    expect($profile->fresh()?->employment_type)->toBe(EmploymentType::Instructor);
});

it('defaults employment type to administrative when the column is not set', function () {
    // Written through the query builder so the factory's random value cannot
    // mask a missing database default.
    $user = User::factory()->create();

    DB::table('staff_profiles')->insert([
        'user_id' => $user->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(StaffProfile::firstOrFail()->employment_type)
        ->toBe(EmploymentType::Administrative);
});

it('scopes a query to instructors only', function () {
    StaffProfile::factory()->instructor()->create();
    StaffProfile::factory()->administrative()->create();
    StaffProfile::factory()->create(['employment_type' => EmploymentType::Support]);

    expect(StaffProfile::instructors()->count())->toBe(1)
        ->and(StaffProfile::count())->toBe(3);
});

it('refuses at the foreign key to hard-delete a user who still holds an employment record', function () {
    /*
     * INVERTED BY P1-T15, domain-integrity finding 4.
     *
     * This test previously asserted the cascade — "deletes the profile when the
     * user is force deleted" — and read as coverage for behaviour that silently
     * destroyed data. staff_profiles cascaded from users, and staff_certificates
     * cascades from staff_profiles, so one hard delete removed a profile and
     * every certificate row by database cascade, wrote no pending_file_deletions
     * receipt for any of them, and left every scanned identity document on disk
     * with nothing left in the database pointing at it. Unreconcilable: the
     * receipt table is the only record of what needs destroying, and the rows
     * that could have populated it were already gone.
     *
     * The constraint now forces the caller through DeleteStaffProfileAction,
     * which reads those paths BEFORE the cascade precisely so the bytes can be
     * collected — the same reason enrollments.batch_id and
     * batch_instructor.batch_id already restrict.
     */
    $user = User::factory()->create();
    StaffProfile::factory()->for($user)->create();

    try {
        $user->forceDelete();
        $thrown = null;
    } catch (QueryException $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(QueryException::class)
        // 1451: "Cannot delete or update a parent row: a foreign key constraint
        // fails". The exact driver code, not merely "something threw" — a NOT
        // NULL violation or a dropped connection is also a QueryException and
        // would prove nothing about the restriction.
        ->and($thrown->errorInfo[1] ?? null)->toBe(1451)
        // Nothing was destroyed on the way to the refusal.
        ->and(User::withTrashed()->whereKey($user->getKey())->exists())->toBeTrue()
        ->and(StaffProfile::count())->toBe(1);
});

it('lets a user with no employment record be hard-deleted', function () {
    /*
     * The control for the restriction above. Without it, a users table that
     * refused every hard delete for some unrelated reason would pass the test
     * above and look like a working constraint.
     */
    $user = User::factory()->create();

    $user->forceDelete();

    expect(User::withTrashed()->whereKey($user->getKey())->exists())->toBeFalse();
});

it('lets the account go once its employment record has been removed through the Action', function () {
    /*
     * The sequence the constraint exists to force, shown working end to end: the
     * profile leaves through DeleteStaffProfileAction, which writes a receipt for
     * every file it owned, and only then is the account destroyable.
     *
     * This is the assertion that makes the refusal above a redirection rather
     * than a dead end.
     */
    $this->seed(RolePermissionSeeder::class);
    Storage::fake('private');
    Queue::fake();

    $admin = User::factory()->create(['is_active' => true]);
    app(SystemRoleWriter::class)->assignRoles($admin, 'admin');

    $user = User::factory()->create();
    $profile = StaffProfile::factory()->for($user)->create();

    $path = 'staff-certificates/'.Str::ulid()->toString().'.pdf';
    Storage::disk('private')->put($path, 'certificate-bytes');
    StaffCertificate::factory()->for($profile, 'staffProfile')->create([
        'disk' => 'private',
        'path' => $path,
    ]);

    app(DeleteStaffProfileAction::class)->execute($admin->fresh(), $profile);

    // The receipt the cascade would have skipped.
    expect(PendingFileDeletion::query()->where('path', $path)->exists())->toBeTrue();

    $user->forceDelete();

    expect(User::withTrashed()->whereKey($user->getKey())->exists())->toBeFalse()
        ->and(StaffProfile::count())->toBe(0);
});

it('keeps the profile when the user is only soft deleted', function () {
    // A departed instructor's employment record survives deactivation and soft
    // deletion. Only the row actually leaving the users table cascades.
    $user = User::factory()->create();
    StaffProfile::factory()->for($user)->create();

    $user->delete();

    expect(StaffProfile::count())->toBe(1)
        ->and(User::withTrashed()->count())->toBe(1);
});

it('still resolves the account behind a profile after the user is soft deleted', function () {
    // Surviving the delete is not enough: without withTrashed() on the relation
    // the SoftDeletes global scope resolves user() to null, leaving a profile
    // nobody can attribute — the register cannot show whose it is, and
    // initials() reads the account name, so the avatar placeholder breaks too.
    $user = User::factory()->create(['name' => 'Departed Instructor']);
    $profile = StaffProfile::factory()->for($user)->create();

    $user->delete();

    $profile = $profile->fresh();

    expect($profile->user)->not->toBeNull()
        ->and($profile->user->name)->toBe('Departed Instructor')
        ->and($profile->user->trashed())->toBeTrue()
        ->and($profile->initials())->toBe('DI');
});

it('eager loads the account of a soft deleted user too', function () {
    // with() takes a different code path to lazy loading, so it needs its own
    // assertion — an eager load that drops trashed users would blank the
    // register wherever it is listed.
    $user = User::factory()->create(['name' => 'Gone Away']);
    StaffProfile::factory()->for($user)->create();
    $user->delete();

    $profile = StaffProfile::with('user')->firstOrFail();

    expect($profile->user)->not->toBeNull()
        ->and($profile->user->name)->toBe('Gone Away');
});

it('stores qualifications as free text and allows them to be absent', function () {
    $prose = 'PhD in Applied Linguistics, University of Tripoli; '
        .'CELTA (Cambridge, 2019); first aid certified.';

    $described = StaffProfile::factory()->create(['qualifications' => $prose]);
    $blank = StaffProfile::factory()->create(['qualifications' => null]);

    expect($described->fresh()?->qualifications)->toBe($prose)
        ->and($blank->fresh()?->qualifications)->toBeNull();
});

it('leaves profile_photo_path null rather than storing a default image', function () {
    $profile = StaffProfile::factory()->create();

    expect($profile->fresh()?->profile_photo_path)->toBeNull();
});

it('stores a path only when a photo has been uploaded', function () {
    $profile = StaffProfile::factory()->withPhoto()->create();

    expect($profile->fresh()?->profile_photo_path)
        ->toStartWith('staff-photos/')
        ->toEndWith('.jpg');
});

it('falls back to initials when there is no profile photo', function (string $name, string $expected) {
    $user = User::factory()->create(['name' => $name]);
    $profile = StaffProfile::factory()->for($user)->create();

    expect($profile->profile_photo_path)->toBeNull()
        ->and($profile->initials())->toBe($expected);
})->with([
    'two names' => ['Amal Ibrahim', 'AI'],
    'single word name' => ['Cher', 'C'],
    'lowercase input' => ['amal ibrahim', 'AI'],
    'more than two words is capped at two' => ['Amal Ibrahim Khalid Mansour', 'AI'],
    'extra whitespace is ignored' => ['  Amal   Ibrahim  ', 'AI'],
    'an empty name yields an empty string, not an error' => ['', ''],
    // Multibyte, because phase 4 brings Arabic names and a byte-wise substr
    // would slice a UTF-8 character in half and produce mojibake. Written as
    // escapes rather than literals so the expected value is unambiguous in a
    // bidirectional editor: "Amal Ibrahim" in Arabic, initials alef-hamza-above
    // then alef-hamza-below.
    'an arabic name is sliced by character, not by byte' => [
        "\u{0623}\u{0645}\u{0644} \u{0625}\u{0628}\u{0631}\u{0627}\u{0647}\u{064A}\u{0645}",
        "\u{0623}\u{0625}",
    ],
]);
