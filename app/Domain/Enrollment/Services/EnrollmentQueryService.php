<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Services;

use App\Domain\Enrollment\Models\Enrollment;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * How Finance reads enrolment data. The whole surface, built once.
 *
 * WHY IT EXISTS, AND WHY IT IS COMPLETE ON ITS FIRST DAY
 * -----------------------------------------------------
 * The system design and `docs/ENGINEERING.md` both name this class as the
 * boundary Finance reads enrolments through — and until this task it did not
 * exist. Design §12 requires it be built with the full surface **four** later
 * tasks need, so that no two of them extend it in parallel branches:
 *
 * | Task | Needs |
 * |---|---|
 * | 4 Payments | the student owning a charge's enrolment |
 * | 6 Receipts | enrolment reference, course and batch codes |
 * | 8 Payroll  | instructor assignments and their hours |
 * | 10 Reports | the enrolment → batch → course path every grouping walks |
 *
 * Revision 2 listed only tasks 8 and 10, which would have left task 4 walking
 * `$charge->enrollment->student` — the exact cross-domain query the architecture
 * test forbids, discovered at implementation time instead of here. If a genuine
 * gap appears later it is raised, not patched twice.
 *
 * IT RETURNS PRIMITIVES AND ARRAYS, NOT ENROLMENT MODELS
 * -----------------------------------------------------
 * A service that hands back an Enrollment has moved the boundary rather than
 * drawn one: the caller can then walk anywhere from it, and the architecture
 * test cannot tell that walk apart from a legitimate read. Every method here
 * answers one question with the smallest value that answers it.
 *
 * The one exception is deliberate and named: instructorAssignmentsFor() returns
 * rows shaped for payroll, because "who taught this batch, for how many hours"
 * is genuinely a list.
 */
final class EnrollmentQueryService
{
    /**
     * The student who owns a charge's enrolment.
     *
     * TASK 4 READS THIS, AND NOTHING ELSE.
     * Design §5: `RecordPaymentData` carries no student field, because a payment
     * belongs to whoever owns the bill and a client-supplied student id is a
     * cross-student allocation waiting to happen. The Action derives the student
     * from the charge it has locked, through here.
     *
     * @throws RuntimeException if the enrolment does not exist.
     */
    public function studentIdFor(int $enrollmentId): int
    {
        $studentId = Enrollment::query()
            ->whereKey($enrollmentId)
            ->value('student_id');

        if ($studentId === null) {
            throw new RuntimeException("There is no enrolment [{$enrollmentId}].");
        }

        return (int) $studentId;
    }

    /**
     * Everything a receipt prints about the enrolment behind a bill.
     *
     * TASK 6 READS THIS. Design §2 lists the fields: the enrolment reference,
     * the course and batch codes, and the student's code and name. One query
     * rather than four, because a receipt is generated in a queued job where a
     * per-field round trip is pure latency.
     *
     * @return array{
     *     enrollment_reference: string,
     *     student_id: int,
     *     student_code: string,
     *     student_name: string,
     *     batch_id: int,
     *     batch_code: string,
     *     course_id: int,
     *     course_code: string,
     * }
     *
     * NO COURSE NAME, DELIBERATELY. Design §2 lists the receipt's fields as the
     * course and batch **codes**, and `courses` is bilingual — `name_en` and
     * `name_ar`. Returning "the name" would mean choosing a language inside a
     * query service, which is a view-layer decision and would hard-code English
     * into every consumer three phases before Arabic ships. If task 6 turns out
     * to need a name, that is raised rather than guessed here.
     *
     * @throws RuntimeException if the enrolment does not exist.
     */
    public function receiptContextFor(int $enrollmentId): array
    {
        /*
         * THE QUERY BUILDER, NOT ELOQUENT, AND THAT IS THE POINT.
         *
         * This projects scalars across four tables; hydrating an Enrollment and
         * hanging joined columns off it would be a model that is not really the
         * row it claims to be — PHPStan says so, and it is right. ChargeBalance
         * is written against table and column names for the same reason.
         *
         * It also keeps the promise in this class's docblock: no Enrollment
         * model leaves this service, so no caller can walk one.
         */
        $row = DB::table('enrollments')
            ->where('enrollments.id', $enrollmentId)
            ->join('students', 'students.id', '=', 'enrollments.student_id')
            ->join('batches', 'batches.id', '=', 'enrollments.batch_id')
            ->join('courses', 'courses.id', '=', 'batches.course_id')
            ->select([
                'enrollments.reference as enrollment_reference',
                'students.id as student_id',
                'students.student_code',
                'students.first_name',
                'students.last_name',
                'batches.id as batch_id',
                'batches.code as batch_code',
                'courses.id as course_id',
                'courses.code as course_code',
            ])
            ->first();

        if ($row === null) {
            throw new RuntimeException("There is no enrolment [{$enrollmentId}].");
        }

        return [
            'enrollment_reference' => (string) $row->enrollment_reference,
            'student_id' => (int) $row->student_id,
            'student_code' => (string) $row->student_code,
            /*
             * Composed here rather than by the caller, and NOT localised: this
             * is a person's name, not a UI string. The receipt template decides
             * where it sits; design §12 puts the composite *labels* in lang/,
             * which is a different thing from the value.
             */
            'student_name' => trim($row->first_name.' '.$row->last_name),
            'batch_id' => (int) $row->batch_id,
            'batch_code' => (string) $row->batch_code,
            'course_id' => (int) $row->course_id,
            'course_code' => (string) $row->course_code,
        ];
    }

    /**
     * The instructors assigned to a batch, with the hours they were assigned.
     *
     * TASK 8 READS THIS. Design §7: an `instructor_batch` payroll run freezes
     * the assigned hours as well as the rate, so a later change to the pivot
     * cannot move a finalized line. The hours come from the pivot, which is why
     * this returns them rather than leaving payroll to join a table it does not
     * own.
     *
     * @return Collection<int, array{user_id: int, assigned_hours: int}>
     */
    public function instructorAssignmentsFor(int $batchId): Collection
    {
        return DB::table('batch_instructor')
            ->where('batch_id', $batchId)
            ->orderBy('user_id')
            ->get(['user_id', 'assigned_hours'])
            ->map(fn (object $row): array => [
                'user_id' => (int) $row->user_id,
                'assigned_hours' => (int) $row->assigned_hours,
            ])
            ->values();
    }

    /**
     * The batch and course an enrolment sits under.
     *
     * TASK 10 READS THIS. Every revenue grouping in design §8 walks
     * enrolment → batch → course, and a report that walked it through Eloquent
     * relations from Finance would be the cross-domain query the architecture
     * test forbids.
     *
     * @return array{batch_id: int, batch_code: string, course_id: int, course_code: string}
     *
     * @throws RuntimeException if the enrolment does not exist.
     */
    public function catalogueContextFor(int $enrollmentId): array
    {
        $context = $this->receiptContextFor($enrollmentId);

        return [
            'batch_id' => $context['batch_id'],
            'batch_code' => $context['batch_code'],
            'course_id' => $context['course_id'],
            'course_code' => $context['course_code'],
        ];
    }
}
