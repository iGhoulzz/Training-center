<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Enrollment\Enums\BatchStatus;
use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Enrollment\Enums\StudentStatus;
use App\Domain\Finance\Enums\TenderMethod;
use App\Domain\Finance\Support\Reference;
use App\Support\PerformanceDatabaseGuard;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A fixed, allocation-heavy fixture for repeatable finance measurements.
 *
 * This is intentionally relational rather than a pile of isolated charges:
 * every charge has an enrolment and student, and each has two standing payments
 * with matching tenders and allocations. Each 1,000.000 charge therefore owes
 * 600.000 through ChargeBalance's canonical derived expression. No balance or
 * paid total is stored.
 *
 * Bulk query-builder inserts are deliberate tooling writes. They avoid model
 * events and an append-only activity row for every synthetic fact, and they are
 * bounded by PerformanceDatabaseGuard before the first write. Normal
 * application writes remain behind their Actions.
 */
final class PerformanceDatasetSeeder extends Seeder
{
    /** The fixed seed mixed into every generated calendar offset. */
    private const FIXED_SEED = 35_005;

    /** Rows per insert, keeping packets and PHP arrays bounded. */
    private const INSERT_CHUNK = 500;

    /**
     * @var array<string, array{charges: int, allocations: int}>
     */
    private const PROFILES = [
        'small' => ['charges' => 1_000, 'allocations' => 2_000],
        'medium' => ['charges' => 4_000, 'allocations' => 8_000],
    ];

    public function __construct(private readonly PerformanceDatabaseGuard $guard) {}

    public static function supportsProfile(string $profile): bool
    {
        return array_key_exists($profile, self::PROFILES);
    }

    /**
     * @return array{charges: int, allocations: int}
     *
     * @throws RuntimeException when the profile is not fixed by this seeder.
     */
    public static function countsFor(string $profile): array
    {
        if (! self::supportsProfile($profile)) {
            throw new RuntimeException("Unknown performance dataset profile [{$profile}].");
        }

        return self::PROFILES[$profile];
    }

    /**
     * Seed one fixed profile after independently enforcing the safety boundary.
     *
     * The required confirmation argument makes a direct invocation name its
     * database just as the command does. The guard runs before DatabaseSeeder or
     * any fixture insert, so calling this class directly cannot bypass it.
     */
    public function run(string $profile, string $confirmedDatabase): void
    {
        $this->guard->assertSafe($confirmedDatabase);

        $counts = self::countsFor($profile);
        $chargeCount = $counts['charges'];

        $this->call(DatabaseSeeder::class);

        $recordedBy = DB::table('users')->where('email', 'owner@example.test')->value('id');

        if (! is_int($recordedBy)) {
            throw new RuntimeException('The performance dataset owner account was not seeded.');
        }

        $timestamp = '2026-06-30 08:00:00';

        DB::table('courses')->insert([
            'id' => 1,
            'code' => 'PERF-COURSE',
            'name_en' => 'Performance fixture course',
            'total_hours' => 40,
            'default_price' => '1000.000',
            'is_active' => true,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('batches')->insert([
            'id' => 1,
            'course_id' => 1,
            'code' => 'PERF-BATCH',
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'capacity' => $chargeCount,
            'total_hours' => null,
            'price' => null,
            'status' => BatchStatus::Active->value,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $this->seedStudentsEnrollmentsAndCharges($chargeCount, $timestamp);
        $this->seedPaymentsTendersAndAllocations($chargeCount, $recordedBy, $timestamp);
    }

    private function seedStudentsEnrollmentsAndCharges(int $chargeCount, string $timestamp): void
    {
        $students = [];
        $enrollments = [];
        $charges = [];
        $asOf = CarbonImmutable::parse('2026-06-30');

        for ($id = 1; $id <= $chargeCount; $id++) {
            $students[] = [
                'id' => $id,
                'user_id' => null,
                'student_code' => 'PERF-'.str_pad((string) $id, 6, '0', STR_PAD_LEFT),
                'first_name' => 'Performance',
                'last_name' => 'Student '.str_pad((string) $id, 6, '0', STR_PAD_LEFT),
                'email' => null,
                'phone' => null,
                'national_id' => null,
                'date_of_birth' => null,
                'gender' => null,
                'address' => null,
                'status' => StudentStatus::Active->value,
                'notes' => null,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
                'deleted_at' => null,
            ];
            $enrollments[] = [
                'id' => $id,
                'student_id' => $id,
                'batch_id' => 1,
                'enrolled_at' => $timestamp,
                'status' => EnrollmentStatus::Active->value,
                'completed_at' => null,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
                'reference' => Reference::format(Reference::ENROLLMENT_PREFIX, 2026, $id),
            ];
            $charges[] = [
                'id' => $id,
                'enrollment_id' => $id,
                'reference' => Reference::format(Reference::CHARGE_PREFIX, 2026, $id),
                'list_price' => '1000.000',
                'discount_id' => null,
                'discount_percentage' => null,
                'amount' => '1000.000',
                'due_date' => $asOf->subDays(($id + self::FIXED_SEED) % 121)->toDateString(),
                'written_off_at' => null,
                'written_off_by' => null,
                'written_off_reason' => null,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];

            if (count($students) === self::INSERT_CHUNK || $id === $chargeCount) {
                DB::table('students')->insert($students);
                DB::table('enrollments')->insert($enrollments);
                DB::table('charges')->insert($charges);

                $students = [];
                $enrollments = [];
                $charges = [];
            }
        }
    }

    private function seedPaymentsTendersAndAllocations(
        int $chargeCount,
        int $recordedBy,
        string $timestamp,
    ): void {
        $payments = [];
        $tenders = [];
        $allocations = [];

        for ($chargeId = 1; $chargeId <= $chargeCount; $chargeId++) {
            for ($sequence = 1; $sequence <= 2; $sequence++) {
                $paymentId = (($chargeId - 1) * 2) + $sequence;

                $payments[] = [
                    'id' => $paymentId,
                    'student_id' => $chargeId,
                    'reference' => Reference::format(Reference::PAYMENT_PREFIX, 2026, $paymentId),
                    'idempotency_key' => 'performance-payment-'.$paymentId,
                    'request_fingerprint' => hash('sha256', "performance-payment-{$paymentId}"),
                    'received_at' => $timestamp,
                    'recorded_by' => $recordedBy,
                    'notes' => null,
                    'reversed_at' => null,
                    'reversed_by' => null,
                    'reversal_reason' => null,
                    'receipt_disk' => null,
                    'receipt_path' => null,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
                $tenders[] = [
                    'id' => $paymentId,
                    'payment_id' => $paymentId,
                    'method' => TenderMethod::Cash->value,
                    'amount' => '200.000',
                    'external_reference' => null,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];
                $allocations[] = [
                    'id' => $paymentId,
                    'payment_id' => $paymentId,
                    'charge_id' => $chargeId,
                    'amount' => '200.000',
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ];

                if (count($payments) === self::INSERT_CHUNK) {
                    DB::table('payments')->insert($payments);
                    DB::table('payment_tenders')->insert($tenders);
                    DB::table('payment_allocations')->insert($allocations);

                    $payments = [];
                    $tenders = [];
                    $allocations = [];
                }
            }
        }

        if ($payments !== []) {
            DB::table('payments')->insert($payments);
            DB::table('payment_tenders')->insert($tenders);
            DB::table('payment_allocations')->insert($allocations);
        }
    }
}
