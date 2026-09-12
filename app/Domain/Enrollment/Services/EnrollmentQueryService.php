<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Services;

use App\Domain\Enrollment\Models\Enrollment;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
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
         * THROUGH THE MODEL, BUT NOT HYDRATED INTO ONE — AND BOTH HALVES ARE
         * ENFORCED BY A TEST THAT ALREADY EXISTED.
         *
         * Not hydrated, because this projects scalars across four tables:
         * hanging joined columns off an Enrollment produces a model that is not
         * really the row it claims to be, which PHPStan rejects and which would
         * let a caller walk a half-built model into Finance.
         *
         * Through the model, because `ActionBoundaryArchTest` forbids
         * `DB::table('enrollments')` in ANY file — "the table is reached through
         * the model or not at all". The first version of this method used the
         * raw builder to satisfy PHPStan and tripped that rule on the full gate.
         *
         * `toBase()` satisfies both: the query is built from Enrollment's own
         * builder, and the result comes back as plain rows rather than models.
         */
        $row = Enrollment::query()
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
            ->toBase()
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
     * THE ASSIGNMENT'S OWN ID IS PART OF THE ANSWER, NOT AN INTERNAL DETAIL.
     * `payroll_lines.batch_instructor_id` is a foreign key to this row, and
     * design §7's "an instructor assignment is paid at most once" unique index
     * is keyed on it. Returning only the user and the hours would leave task 8
     * unable to write a payroll line at all — the independent review of P2-T03
     * caught that, and it is exactly the "build the whole surface once" failure
     * this class exists to prevent.
     *
     * @return Collection<int, array{id: int, batch_id: int, user_id: int, assigned_hours: int}>
     */
    public function instructorAssignmentsFor(int $batchId): Collection
    {
        return $this->instructorAssignmentRows(
            DB::table('batch_instructor')->where('batch_id', $batchId)
        );
    }

    /**
     * Every instructor assignment in the centre, for a payroll run that is not
     * scoped to one batch.
     *
     * Design §7: an `instructor_batch` run "lists every instructor-hour
     * assignment not already paid in a finalized run, **whatever the batch's
     * status**". Task 8 cannot enumerate batch ids first and call the per-batch
     * method — walking Enrolment's tables to build that list is precisely the
     * cross-domain query this boundary exists to prevent.
     *
     * Which assignments have already been paid is Finance's own question,
     * answered from `payroll_lines`, so it is deliberately not a parameter here.
     *
     * @return Collection<int, array{id: int, batch_id: int, user_id: int, assigned_hours: int}>
     */
    public function allInstructorAssignments(): Collection
    {
        return $this->instructorAssignmentRows(DB::table('batch_instructor'));
    }

    /**
     * @return Collection<int, array{
     *     id: int,
     *     batch_id: int,
     *     batch_code: string,
     *     user_id: int,
     *     user_name: string,
     *     assigned_hours: int
     * }>
     */
    public function searchInstructorAssignments(
        Builder $excludedAssignmentIds,
        string $search,
        int $limit,
    ): Collection {
        if ($limit < 1) {
            throw new InvalidArgumentException('The instructor assignment search limit must be positive.');
        }

        return $this->labelledInstructorAssignmentRows(
            DB::table('batch_instructor')
                ->whereNotIn('batch_instructor.id', $excludedAssignmentIds)
                ->where(function (Builder $query) use ($search): void {
                    $query
                        ->where('users.name', 'like', "%{$search}%")
                        ->orWhere('batches.code', 'like', "%{$search}%");
                })
                ->limit(min($limit, 25)),
        );
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, array{
     *     id: int,
     *     batch_id: int,
     *     batch_code: string,
     *     user_id: int,
     *     user_name: string,
     *     assigned_hours: int
     * }>
     */
    public function instructorAssignmentsById(array $ids): Collection
    {
        $selectedIds = collect($ids)
            ->map(fn (int|string $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($selectedIds === []) {
            return collect();
        }

        return $this->labelledInstructorAssignmentRows(
            DB::table('batch_instructor')->whereIn('batch_instructor.id', $selectedIds),
        );
    }

    /**
     * @return Collection<int, array{id: int, batch_id: int, user_id: int, assigned_hours: int}>
     */
    private function instructorAssignmentRows(Builder $query): Collection
    {
        return $query
            ->orderBy('batch_id')
            ->orderBy('user_id')
            ->get(['id', 'batch_id', 'user_id', 'assigned_hours'])
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'batch_id' => (int) $row->batch_id,
                'user_id' => (int) $row->user_id,
                'assigned_hours' => (int) $row->assigned_hours,
            ])
            ->values();
    }

    /**
     * @return Collection<int, array{
     *     id: int,
     *     batch_id: int,
     *     batch_code: string,
     *     user_id: int,
     *     user_name: string,
     *     assigned_hours: int
     * }>
     */
    private function labelledInstructorAssignmentRows(Builder $query): Collection
    {
        return $query
            ->join('batches', 'batches.id', '=', 'batch_instructor.batch_id')
            ->join('users', 'users.id', '=', 'batch_instructor.user_id')
            ->orderBy('batches.code')
            ->orderBy('users.name')
            ->get([
                'batch_instructor.id',
                'batch_instructor.batch_id',
                'batches.code as batch_code',
                'batch_instructor.user_id',
                'users.name as user_name',
                'batch_instructor.assigned_hours',
            ])
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'batch_id' => (int) $row->batch_id,
                'batch_code' => (string) $row->batch_code,
                'user_id' => (int) $row->user_id,
                'user_name' => (string) $row->user_name,
                'assigned_hours' => (int) $row->assigned_hours,
            ])
            ->values();
    }

    /** Group by the batch an enrolment sits on. */
    public const DIMENSION_BATCH = 'batch';

    /** Group by the course, across every batch of it. */
    public const DIMENSION_COURSE = 'course';

    /**
     * The column aliases {@see joinCatalogueTo()} makes available.
     *
     * Named rather than spelled out at both ends, following
     * ChargeBalance::OUTSTANDING_ALIAS, so a report groups on a constant rather
     * than a string it retyped. A mistyped alias is reported by MySQL as an
     * unknown column — loud, and the reason to prefer the constant is
     * refactorability rather than silence.
     */
    public const BATCH_ID = 'catalogue_batch_id';

    public const BATCH_CODE = 'catalogue_batch_code';

    public const COURSE_ID = 'catalogue_course_id';

    public const COURSE_CODE = 'catalogue_course_code';

    /**
     * Add the enrolment → batch → course path to somebody else's query.
     *
     * TASK 10 GROUPS AND SUMS IN SQL, SO IT NEEDS A JOIN, NOT LOOKUPS.
     * This replaced a `catalogueContextFor($enrollmentId)` lookup, which was
     * removed rather than left beside it: it answered one enrolment per call,
     * which a revenue
     * report can only use by calling it per row and summing in PHP — an N+1, and
     * against design §6's rule that aggregation happens in SQL where MySQL's
     * DECIMAL sums are exact. The cross-review of P2-T03 caught that this
     * service satisfied the *shape* of the four-consumer contract while leaving
     * task 10 unable to write its query without extending it.
     *
     * The join is contributed by THIS class rather than written in Finance, so
     * the knowledge of how enrolments reach courses stays on this side of the
     * boundary and the architecture rule keeps meaning something. Callers get
     * aliases, not table names.
     *
     * THE CALLER STATES ITS DIMENSION, AND THE SELECTION FOLLOWS IT.
     * The first version selected all four columns unconditionally, which works
     * for a per-batch grouping only because a course is functionally determined
     * by its batch. A revenue-by-course report — design §8 requires one — then
     * has no good option: under `ONLY_FULL_GROUP_BY` MySQL rejects the ungrouped
     * batch columns, and adding them to the `GROUP BY` silently turns the answer
     * into one row per batch. Cross-review of P2-T03 caught it. `$dimensions` is
     * required rather than defaulted, because a default is how the caller ends
     * up with columns it did not ask for.
     *
     * @param  Builder  $query  A query already selecting from a table that
     *                          carries an enrolment id.
     *                          THE PRESERVATION IS DEFEATED BY ANY CALLER PREDICATE ON THE JOINED
     *                          TABLE, AND THAT IS THE TRAP THIS METHOD CARRIES.
     *                          ---------------------------------------------------------------------
     *                          An outer join keeps the unmatched rows, but a WHERE on the NON-preserved
     *                          side then throws them straight back out — a NULL-padded row fails almost
     *                          any predicate. Measured against this project's MySQL:
     *
     *     no extra predicate  → [{enrollment_id: 1, charge_id: 1},
     *                            {enrollment_id: 2, charge_id: null}]
     *     AND charges.amount > 0  → [{enrollment_id: 1, charge_id: 1}]
     *
     * So a caller that adds `->where('charges.amount', '>', 0)` to hide zero
     * bills silently loses every UNBILLED enrolment as well — the exact
     * vanishing this method exists to prevent, on the page where a student is
     * meant to see all of their enrolments.
     *
     * The most likely filter happens to be safe, which makes the trap worse
     * rather than better: `->whereNull('charges.written_off_at')` KEEPS the
     * padded rows, because NULL IS NULL. So the first caller to filter may well
     * get away with it and the second may not.
     *
     * RULE FOR CALLERS: put predicates on `enrollments` freely — that is the
     * preserved side. A predicate on the joined table must be moved into the
     * join's ON clause, or expressed as `(<predicate> OR <column> IS NULL)`.
     * `ScopeToStudentPreservationTest` pins both halves.
     * @param  string  $enrollmentIdColumn  Qualified, e.g. `charges.enrollment_id`.
     *                                      `list<string>` rather than `non-empty-list<self::DIMENSION_*>`: this is a
     *                                      boundary that validates caller input, and annotating the argument as
     *                                      already-valid makes the check below unreachable by PHPStan's reckoning —
     *                                      which is a claim about the caller, not about this method.
     * @param  list<string>  $dimensions  One or more self::DIMENSION_* values.
     *
     * @throws InvalidArgumentException on an unknown or empty dimension.
     */
    public function joinCatalogueTo(Builder $query, string $enrollmentIdColumn, array $dimensions): Builder
    {
        $columns = [
            self::DIMENSION_BATCH => [
                'batches.id as '.self::BATCH_ID,
                'batches.code as '.self::BATCH_CODE,
            ],
            self::DIMENSION_COURSE => [
                'courses.id as '.self::COURSE_ID,
                'courses.code as '.self::COURSE_CODE,
            ],
        ];

        $unknown = array_diff($dimensions, array_keys($columns));

        if ($dimensions === [] || $unknown !== []) {
            throw new InvalidArgumentException(
                'Name at least one known catalogue dimension: '.implode(', ', array_keys($columns)).'.'
            );
        }

        /*
         * `batches` is joined even for a course-only query — it is the path from
         * an enrolment to its course — but its columns are not selected, which
         * is what keeps the aggregate one row per course.
         */
        $query
            ->join('enrollments', 'enrollments.id', '=', $enrollmentIdColumn)
            ->join('batches', 'batches.id', '=', 'enrollments.batch_id')
            ->join('courses', 'courses.id', '=', 'batches.course_id');

        foreach ($dimensions as $dimension) {
            $query->addSelect($columns[$dimension]);
        }

        return $query;
    }

    /**
     * Add "and this row belongs to this student" to somebody else's query — the
     * operation the portal cannot be allowed to take half of.
     *
     * A SECURITY-SHAPED OPERATION, NOT A JOIN HELPER.
     * ------------------------------------------------
     * The carried item was recorded as joinStudentTo(). A method that only
     * joins leaves every caller responsible for remembering the WHERE clause —
     * the exact part that must never be forgotten on the portal, where every
     * page renders one student's own data and nothing belonging to anyone
     * else. scopeToStudent() joins *and* constrains in one call, so there is
     * no code path here that returns an unconstrained query. Design §11.4.
     *
     * THE SIGNATURE MIRRORS joinCatalogueTo() ON PURPOSE, so the two read as
     * siblings: one attaches the catalogue path, the other attaches and
     * enforces ownership.
     *
     * A RIGHT JOIN, NOT joinCatalogueTo()'S INNER ONE, AND THAT IS THE WHOLE
     * REASON THIS METHOD EXISTS RATHER THAN REUSING THAT ONE.
     * ---------------------------------------------------------------------
     * joinCatalogueTo() drops a row the caller's table has nothing to say
     * about, which is correct for a revenue report — an unbilled enrolment
     * contributes nothing to a SUM and is rightly absent. The portal is the
     * opposite case: an unbilled enrolment must still render on "my balance"
     * as zero owed, and an uncertified one must still render on "my
     * enrolments" with no certificate fields — neither is allowed to vanish
     * because the caller's table (`charges`, `student_certificates`, …) has no
     * row for it. A RIGHT JOIN keeps `enrollments` in full regardless of
     * whether the caller's side matches, NULL-padding the caller's columns
     * exactly where StudentBalanceQuery reads "no bill" from a null charge id.
     *
     * @param  Builder  $query  A query already selecting from a table that
     *                          carries an enrolment id — see joinCatalogueTo()
     *                          for the same contract. This is never the
     *                          `enrollments` table itself: joining it to
     *                          itself under one alias is a MySQL error, and
     *                          every sanctioned caller reaches `enrollments`
     *                          through some other table's foreign key, exactly
     *                          as joinCatalogueTo()'s callers do.
     * @param  string  $enrollmentIdColumn  Qualified, e.g. `charges.enrollment_id`.
     */
    public function scopeToStudent(Builder $query, string $enrollmentIdColumn, int $studentId): Builder
    {
        return $query
            ->rightJoin('enrollments', 'enrollments.id', '=', $enrollmentIdColumn)
            // On `enrollments`, the PRESERVED side. A predicate here filters
            // which enrolments survive; one on the joined side would discard
            // the NULL-padded rows entirely. See the trap above.
            ->where('enrollments.student_id', $studentId);
    }
}
