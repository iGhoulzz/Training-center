<?php

declare(strict_types=1);

use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\Student;
use App\Domain\Enrollment\Services\EnrollmentQueryService;
use App\Domain\Finance\Models\Charge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| What scopeToStudent() preserves, and what defeats it (P3-T09, review F1)
|--------------------------------------------------------------------------
|
| The RIGHT JOIN exists so an UNBILLED enrolment still appears on the portal's
| balance page as zero owed, rather than vanishing because `charges` has no row
| for it.
|
| An outer join's preservation is fragile in a way that is easy to miss: a WHERE
| on the NON-preserved side throws the padded rows straight back out, because a
| NULL fails almost any predicate. The method's docblock says so; these tests are
| what make that statement checkable, because a warning nobody executes is
| exactly the comment-asserting-a-property defect this repository keeps finding.
|
| The next caller is T7's balance page, and after it whatever queries the
| certificate register. This is the trap they will meet.
*/

/** A student with one billed enrolment and one unbilled one. */
function preservationFixture(): Student
{
    $student = Student::factory()->create();

    $billed = Enrollment::factory()->for($student)->create();
    Charge::factory()->for($billed)->create(['amount' => '500.000']);

    Enrollment::factory()->for($student)->create();

    return $student;
}

/** The bare scoped query, as StudentBalanceQuery builds it. */
function scopedCharges(Student $student)
{
    return app(EnrollmentQueryService::class)->scopeToStudent(
        DB::table('charges'),
        'charges.enrollment_id',
        (int) $student->getKey(),
    )->select(['enrollments.id as enrollment_id', 'charges.id as charge_id']);
}

it('keeps an unbilled enrolment when nothing filters the joined side', function () {
    // The property the RIGHT JOIN exists for. Two enrolments, one bill.
    $rows = scopedCharges(preservationFixture())->orderBy('enrollments.id')->get();

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->charge_id)->not->toBeNull()
        ->and($rows[1]->charge_id)->toBeNull();
});

it('loses the unbilled enrolment as soon as a predicate lands on the joined side', function () {
    /*
     * THE TRAP, EXECUTED RATHER THAN DESCRIBED.
     *
     * A caller hiding zero-value bills writes the obvious thing and silently
     * drops every unbilled enrolment with them. This test does NOT assert
     * desirable behaviour — it pins the hazard, so that a future reader meets it
     * here rather than on a student's balance page.
     */
    $rows = scopedCharges(preservationFixture())
        ->where('charges.amount', '>', 0)
        ->get();

    expect($rows)->toHaveCount(1, 'The unbilled enrolment survived a predicate on the joined side.');
});

it('keeps the unbilled enrolment when the predicate is written null-safely', function () {
    // The prescribed workaround from the docblock: allow the padded row through
    // explicitly. This is what a caller should write instead.
    $rows = scopedCharges(preservationFixture())
        ->where(function ($query): void {
            $query->where('charges.amount', '>', 0)->orWhereNull('charges.id');
        })
        ->get();

    expect($rows)->toHaveCount(2);
});

it('keeps the unbilled enrolment under a written_off_at filter, which is why the trap is easy to miss', function () {
    /*
     * The most likely filter of all is SAFE, because a NULL-padded row satisfies
     * IS NULL. So the first caller to filter may well get away with it and the
     * second may not — which is what makes documenting the rule worth more than
     * documenting the one failing case.
     */
    $rows = scopedCharges(preservationFixture())
        ->whereNull('charges.written_off_at')
        ->get();

    expect($rows)->toHaveCount(2);
});

it('filters freely on the preserved side without losing padded rows', function () {
    // enrollments is the preserved side, so a predicate there is always safe —
    // it decides which enrolments survive, which is the intended question.
    $student = preservationFixture();

    $rows = scopedCharges($student)
        ->whereNotNull('enrollments.enrolled_at')
        ->get();

    expect($rows)->toHaveCount(2);
});

it('never returns another student\'s enrolment', function () {
    // The security half. The join is worthless if the scoping is not also true.
    $mine = preservationFixture();
    $theirs = preservationFixture();

    $rows = scopedCharges($mine)->get();
    $ids = collect($rows)->pluck('enrollment_id')->all();

    $otherIds = Enrollment::query()->where('student_id', $theirs->getKey())->pluck('id')->all();

    expect(array_intersect($ids, $otherIds))->toBeEmpty();
});
