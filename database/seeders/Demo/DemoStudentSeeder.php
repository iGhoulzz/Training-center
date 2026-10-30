<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Domain\Enrollment\Actions\CompleteEnrollmentAction;
use App\Domain\Enrollment\Actions\CreateWithIdentifierCodeAction;
use App\Domain\Enrollment\Actions\DeleteStudentAction;
use App\Domain\Enrollment\Actions\IssueStudentCertificateAction;
use App\Domain\Enrollment\Actions\ReplaceStudentCertificateAction;
use App\Domain\Enrollment\Actions\RevokeStudentCertificateAction;
use App\Domain\Enrollment\Actions\WithdrawEnrollmentAction;
use App\Domain\Enrollment\Enums\StudentStatus;
use App\Domain\Finance\Actions\EnrollAndBillAction;
use App\Domain\Finance\Data\EnrollAndBillData;
use App\Domain\Staff\Actions\IssuePortalCredentialAction;
use App\Models\User;

/**
 * The people the centre teaches, and what happened to each of their seats.
 *
 * MIRRORS CreateStudent (app/Domain/Enrollment/Filament/Resources/StudentResource/Pages/CreateStudent.php:90,
 * handleRecordCreation): CreateWithIdentifierCodeAction::createStudent(), with the code left
 * blank so the application mints it. A later status change mirrors EditStudent, which is
 * Filament's ordinary edit save (a plain update by the signed-in admin).
 *
 * Every seat is taken through EnrollAndBillAction, the only application path that puts a
 * student on a batch, so each enrolment arrives with its bill. Completion, withdrawal,
 * certificates, portal logins and the removal of a student all use their own Actions,
 * each run by an account that really holds the permission it needs: the instructor who
 * taught the batch marks completion (complete_assigned_batch_enrollment), the admin
 * withdraws and certifies, and the front desk issues portal logins.
 */
final class DemoStudentSeeder extends DemoAreaSeeder
{
    /** Students who log in to the portal: [student number => [who issues it, days from today]]. */
    private const PORTAL_STUDENTS = [5 => ['frontdesk1', -100], 30 => ['frontdesk2', -30]];

    public function run(): void
    {
        $this->registerEnquiries();

        $seen = [];
        $rows = DemoScenarios::enrolments();

        // A student with a seat still open on a running or upcoming batch is neither
        // graduated nor inactive yet, so their earlier outcomes leave the status alone.
        $stillStudying = [];
        foreach ($rows as $row) {
            if ($row['end'] !== 'withdraw' && in_array(DemoScenarios::batches()[$row['batch']]['state'], ['running', 'upcoming'], true)) {
                $stillStudying[$row['student']] = true;
            }
        }

        foreach ($rows as $row) {
            $batch = DemoScenarios::batches()[$row['batch']];
            $enrolDay = $batch['start'] + $row['offset'];

            if (! isset($seen[$row['student']])) {
                $seen[$row['student']] = true;
                $this->registerStudent($row['student'], $enrolDay, $row['by'], StudentStatus::Active);
            }

            $this->registerEnrolment($row, $enrolDay);
            $this->registerOutcome($row, $batch, $enrolDay, isset($stillStudying[$row['student']]));
        }

        foreach (self::PORTAL_STUDENTS as $number => [$issuer, $day]) {
            $this->registerPortalLogin($number, $issuer, $day);
        }
    }

    /** Two people who never enrol: one the desk keeps as an enquiry, one it later removes. */
    private function registerEnquiries(): void
    {
        $context = $this->context;

        $this->registerStudent(47, -40, 'frontdesk1', StudentStatus::Prospective);
        $this->registerStudent(48, -3, 'frontdesk2', StudentStatus::Prospective);

        $context->timeline->add($context->day(-20), 12, 'remove the enquiry that never enrolled', function () use ($context): void {
            $context->by('admin', fn (User $actor) => app(DeleteStudentAction::class)->execute($actor, $context->students['47']));
        });
    }

    private function registerStudent(int $number, int $day, string $creator, StudentStatus $status): void
    {
        $context = $this->context;

        $context->timeline->add($context->day($day), 9, "register student {$number}", function () use ($context, $number, $creator, $status): void {
            [$first, $last, $gender] = DemoScenarios::students()[$number];

            $context->students[(string) $number] = $context->by($creator, fn (User $actor) => app(CreateWithIdentifierCodeAction::class)->createStudent($actor,
                [
                    'student_code' => '',
                    'first_name' => $first,
                    'last_name' => $last,
                    'email' => sprintf('student%02d@example.test', $number),
                    'phone' => sprintf('+218 91 000 %04d', $number),
                    'gender' => $gender,
                    'date_of_birth' => sprintf('%d-%02d-%02d', 1990 + ($number * 7) % 14, 1 + ($number * 5) % 12, 1 + ($number * 11) % 27),
                    'address' => "Demo Street {$number}",
                    'status' => $status,
                    'notes' => $number % 6 === 0 ? 'Prefers evening classes.' : null,
                ],
            ));
        });
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function registerEnrolment(array $row, int $enrolDay): void
    {
        $context = $this->context;
        $id = "{$row['student']}:{$row['batch']}";

        $context->timeline->add($context->day($enrolDay), 10, "enrol {$id}", function () use ($context, $row, $id): void {
            $discount = $row['discount'] === null ? null : (int) $context->discounts[$row['discount']]->getKey();

            $context->enrollments[$id] = $context->by($row['by'], fn (User $actor) => app(EnrollAndBillAction::class)->execute($actor,
                new EnrollAndBillData(
                    (int) $context->students[(string) $row['student']]->getKey(),
                    (int) $context->batches[$row['batch']]->getKey(),
                    $discount,
                ),
            ));
        });
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $batch
     */
    private function registerOutcome(array $row, array $batch, int $enrolDay, bool $stillStudying): void
    {
        $context = $this->context;
        $id = "{$row['student']}:{$row['batch']}";

        if ($row['end'] === 'complete') {
            $endDay = $batch['end'];
            $teacher = array_key_first($batch['instructors']);

            $context->timeline->add($context->day($endDay), 11, "complete {$id}", function () use ($context, $id, $teacher): void {
                $context->by($teacher, fn (User $actor) => app(CompleteEnrollmentAction::class)->execute($actor, $context->enrollments[$id]));
            });

            if (! $stillStudying) {
                $this->registerStatusChange($row['student'], $endDay, StudentStatus::Graduated);
            }

            $this->registerCertificate($id, $endDay, $row['certificate']);
        }

        if ($row['end'] === 'withdraw') {
            $withdrawDay = $enrolDay + $row['end_after'];

            $context->timeline->add($context->day($withdrawDay), 12, "withdraw {$id}", function () use ($context, $id): void {
                $context->by('admin', fn (User $actor) => app(WithdrawEnrollmentAction::class)->execute($actor, $context->enrollments[$id]));
            });

            if (! $stillStudying) {
                $this->registerStatusChange($row['student'], $withdrawDay, StudentStatus::Inactive);
            }
        }
    }

    private function registerStatusChange(int $number, int $day, StudentStatus $status): void
    {
        $context = $this->context;

        $context->timeline->add($context->day($day), 12, "student {$number} becomes {$status->value}", function () use ($context, $number, $status): void {
            $student = $context->students[(string) $number];

            $context->as($context->user('admin'), fn (): bool => $student->update(['status' => $status]));
        });
    }

    private function registerCertificate(string $id, int $endDay, ?string $plan): void
    {
        if ($plan === null) {
            return;
        }

        $context = $this->context;

        $context->timeline->add($context->day($endDay + 2), 12, "certificate for {$id}", function () use ($context, $id): void {
            $context->by('admin', fn (User $actor) => app(IssueStudentCertificateAction::class)->execute($actor, $context->enrollments[$id]));
        });

        if ($plan === 'issue+revoke') {
            $context->timeline->add($context->day($endDay + 25), 12, "revoke certificate for {$id}", function () use ($context, $id): void {
                $context->by('admin', fn (User $actor) => app(RevokeStudentCertificateAction::class)->execute($actor,
                    $context->enrollments[$id],
                    'The name was misspelled on the certificate; it is to be reissued after the record is corrected.',
                ));
            });
        }

        if ($plan === 'issue+replace') {
            $context->timeline->add($context->day($endDay + 10), 12, "replace certificate for {$id}", function () use ($context, $id): void {
                $context->by('admin', fn (User $actor) => app(ReplaceStudentCertificateAction::class)->execute($actor, $context->enrollments[$id]));
            });
        }
    }

    private function registerPortalLogin(int $number, string $issuer, int $day): void
    {
        $context = $this->context;

        $context->timeline->add($context->day($day), 14, "portal login for student {$number}", function () use ($context, $number, $issuer): void {
            $student = $context->students[(string) $number];

            $password = $context->by($issuer, fn (User $actor) => app(IssuePortalCredentialAction::class)->execute($actor, $student));

            $context->portalLogins[] = [
                'name' => $student->full_name,
                'email' => (string) $student->email,
                'password' => $password,
            ];
        });
    }
}
