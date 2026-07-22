<?php

declare(strict_types=1);

use App\Domain\Enrollment\Enums\StudentStatus;
use App\Domain\Enrollment\Models\Student;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * The student record itself.
 *
 * These tests pin the parts the database enforces — the unique code, the
 * nullable account link and what happens when that account goes away, the
 * status default, the soft delete — because each one is invisible in
 * application code and would otherwise surface only in production.
 */
uses(RefreshDatabase::class);

it('requires a unique student code', function () {
    // The code is quoted at the desk and printed on paperwork, so two students
    // sharing one is a real-world ambiguity, not merely a broken index.
    Student::factory()->create(['student_code' => 'STU-0001']);
    Student::factory()->create(['student_code' => 'STU-0001']);
})->throws(UniqueConstraintViolationException::class);

it('allows a student with no linked user account', function () {
    // The common case, not a degenerate one: most students never sign in.
    $student = Student::factory()->create(['user_id' => null]);

    expect($student->exists)->toBeTrue()
        ->and($student->user_id)->toBeNull()
        ->and($student->user)->toBeNull();
});

it('resolves the portal account of a student who has one', function () {
    $student = Student::factory()->withAccount()->create();

    expect($student->user)->not->toBeNull()
        ->and($student->user?->id)->toBe($student->user_id);
});

it('exposes a full name accessor', function () {
    $student = Student::factory()->create([
        'first_name' => 'Amal',
        'last_name' => 'Ibrahim',
    ]);

    expect($student->full_name)->toBe('Amal Ibrahim');
});

it('scopes to active students', function () {
    Student::factory()->create(['status' => StudentStatus::Active]);
    Student::factory()->inactive()->create();
    Student::factory()->prospective()->create();
    Student::factory()->graduated()->create();

    expect(Student::active()->count())->toBe(1)
        ->and(Student::count())->toBe(4);
});

it('casts status to the enum', function () {
    $student = Student::factory()->graduated()->create();

    expect($student->fresh()?->status)->toBe(StudentStatus::Graduated);
});

it('defaults status to prospective when the column is not set', function () {
    // Written through the query builder so the factory's explicit status
    // cannot mask a missing database default. A row inserted by an import
    // script or a console command must still land in a known state.
    DB::table('students')->insert([
        'student_code' => 'STU-RAW-0001',
        'first_name' => 'Raw',
        'last_name' => 'Insert',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(Student::firstOrFail()->status)->toBe(StudentStatus::Prospective);
});

it('soft deletes rather than removing the row', function () {
    // A withdrawn student is still referenced by enrolments and paperwork, so
    // the row leaves the register without leaving the database.
    $student = Student::factory()->create();

    $student->delete();

    expect(Student::count())->toBe(0)
        ->and(Student::withTrashed()->count())->toBe(1)
        ->and(Student::withTrashed()->firstOrFail()->trashed())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| The load-bearing foreign key: deleting a login must not delete a student
|--------------------------------------------------------------------------
*/

it('keeps the student when their portal account is soft deleted', function () {
    $student = Student::factory()->withAccount()->create();
    $user = $student->user;

    $user?->delete();

    $student = $student->fresh();

    expect($student)->not->toBeNull()
        ->and($student?->trashed())->toBeFalse()
        // A soft delete is an UPDATE, so the database foreign key never fires
        // and the column still points at the row. This is the honest outcome,
        // not an oversight: the account still exists and can be restored.
        ->and($student?->user_id)->toBe($user?->id)
        // The relation resolves to null, because User applies the SoftDeletes
        // global scope and this relation deliberately does NOT add
        // withTrashed() — unlike StaffProfile::user(), which needs the trashed
        // account to know whose record it is. A student carries its own name,
        // so "which account does this student sign in with" is honestly none.
        ->and($student?->user)->toBeNull();
});

it('nulls user_id and keeps the student when their portal account is force deleted', function () {
    // The FK behaviour the whole table hangs on. nullOnDelete severs the link;
    // cascade would have destroyed the student's entire file the day someone
    // tidied up an unused account.
    $student = Student::factory()->withAccount()->create([
        'first_name' => 'Amal',
        'last_name' => 'Ibrahim',
    ]);
    $user = $student->user;

    expect($student->user_id)->not->toBeNull();

    $user?->forceDelete();

    $student = $student->fresh();

    expect($student)->not->toBeNull()
        ->and($student?->trashed())->toBeFalse()
        ->and($student?->user_id)->toBeNull()
        ->and($student?->user)->toBeNull()
        // Everything the centre actually keeps is untouched.
        ->and($student?->full_name)->toBe('Amal Ibrahim')
        ->and(Student::count())->toBe(1)
        ->and(User::withTrashed()->count())->toBe(0);
});

it('nulls user_id when a soft deleted account is later purged', function () {
    // The realistic sequence: an account is soft deleted, then force deleted
    // later. The link has to survive the first step and be severed by the
    // second, leaving the student standing either way.
    $student = Student::factory()->withAccount()->create();
    $user = $student->user;

    $user?->delete();

    expect($student->fresh()?->user_id)->toBe($user?->id);

    $user?->forceDelete();

    expect($student->fresh()?->user_id)->toBeNull()
        ->and($student->fresh()?->trashed())->toBeFalse();
});
