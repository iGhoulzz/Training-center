<?php

declare(strict_types=1);

use App\Domain\Enrollment\Models\Student;
use App\Domain\Enrollment\Support\AuthenticatedStudent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The single answer to "whose portal is this"
|--------------------------------------------------------------------------
|
| Every portal page resolves the viewing student through this class. No page
| derives a student_id for itself, and PortalScopeArchTest (T7) proves none does.
|
| That architecture test is a source scan, so it proves a call was made and NOT
| that the resulting query was constrained. This class is the other half: one
| place that decides who is viewing, so there is one thing to get right and one
| thing to test.
|
| It THROWS rather than returning null. A null would be a value every caller must
| remember to check, on the surface where forgetting means showing one student
| another student's record. There is no correct portal page for a user with no
| student row, and canAccessPanel already refuses that account — so reaching here
| in that state is a broken invariant, not a case to render.
*/

it('resolves the student linked to the signed-in user', function () {
    $user = User::factory()->create();
    $student = Student::factory()->for($user)->create();

    $this->actingAs($user);

    expect(app(AuthenticatedStudent::class)->resolve()->getKey())
        ->toBe($student->getKey());
});

it('throws when the signed-in user has no linked student', function () {
    // A staff account, or a student account whose record was unlinked. Either
    // way there is nothing to show and no safe default.
    $this->actingAs(User::factory()->create());

    app(AuthenticatedStudent::class)->resolve();
})->throws(RuntimeException::class);

it('throws when nobody is signed in', function () {
    app(AuthenticatedStudent::class)->resolve();
})->throws(RuntimeException::class);

it('refuses a soft-deleted student record', function () {
    // Student uses SoftDeletes. A deleted record must not keep serving its
    // former holder a portal — the account outliving the record is exactly the
    // case canAccessPanel's "linked, non-trashed" clause exists for, and this
    // asserts the resolver agrees rather than trusting the panel gate alone.
    $user = User::factory()->create();
    $student = Student::factory()->for($user)->create();
    $student->delete();

    $this->actingAs($user);

    app(AuthenticatedStudent::class)->resolve();
})->throws(RuntimeException::class);

it('never returns another student', function () {
    // The failure this whole class exists to prevent, asserted directly rather
    // than inferred from the happy path.
    $viewer = User::factory()->create();
    $mine = Student::factory()->for($viewer)->create();

    $other = Student::factory()->for(User::factory()->create())->create();

    $this->actingAs($viewer);

    $resolved = app(AuthenticatedStudent::class)->resolve();

    expect($resolved->getKey())->toBe($mine->getKey())
        ->and($resolved->getKey())->not->toBe($other->getKey());
});
