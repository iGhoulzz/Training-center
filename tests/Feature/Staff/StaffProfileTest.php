<?php

declare(strict_types=1);

use App\Domain\Staff\Enums\EmploymentType;
use App\Domain\Staff\Models\StaffProfile;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * The staff profile is the employment record beside a login account. These
 * tests pin the parts of it that are structural — the 1:1 constraint, the
 * cascade, the enum cast — because each is enforced by the database and would
 * otherwise only be discovered in production.
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

it('deletes the profile when the user is force deleted', function () {
    $user = User::factory()->create();
    StaffProfile::factory()->for($user)->create();

    $user->forceDelete();

    expect(StaffProfile::count())->toBe(0);
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
