<?php

declare(strict_types=1);

namespace App\Domain\Finance\Exports;

use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Student;
use App\Domain\Finance\Enums\TenderMethod;
use App\Domain\Finance\Models\PaymentAllocation;
use App\Domain\Finance\Models\PaymentTender;
use App\Domain\Finance\Reports\DailyTenderReport;
use App\Domain\Finance\Reports\OutstandingAgedReport;
use App\Domain\Finance\Reports\ProfitReport;
use App\Domain\Finance\Reports\RevenueReport;
use App\Domain\Finance\Reports\StudentPaymentHistory;
use App\Domain\Finance\Reports\TenderBreakdownReport;
use App\Domain\Finance\Reports\WageCostReport;
use App\Domain\Finance\Support\Money;
use App\Domain\Finance\Support\ReportPeriod;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use LogicException;

/** Normalizes the tested T10 report results for screens, XLSX, and PDF. */
final readonly class ReportDataBuilder
{
    public function __construct(
        private RevenueReport $revenue,
        private OutstandingAgedReport $outstanding,
        private TenderBreakdownReport $tenders,
        private DailyTenderReport $dailyTender,
        private WageCostReport $wageCost,
        private ProfitReport $profit,
        private StudentPaymentHistory $studentHistory,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     *
     * @throws AuthorizationException
     */
    public function build(ReportKind $kind, array $options, User $requester): ReportDataset
    {
        Gate::forUser($requester)->authorize('view_financial_report');

        return match ($kind) {
            ReportKind::Revenue => $this->revenue($options),
            ReportKind::OutstandingAged => $this->outstanding($options),
            ReportKind::PaymentMethod => $this->paymentMethods($options),
            ReportKind::DailyTender => $this->dailyTender($options),
            ReportKind::WageCost => $this->wageCost($options),
            ReportKind::Profit => $this->profit($options, $requester),
            ReportKind::StudentPaymentHistory => $this->studentHistory($options),
        };
    }

    /** @param array<string, mixed> $options */
    private function revenue(array $options): ReportDataset
    {
        $period = $this->period($options);
        $courseTotals = $this->revenue->byCourse($period)->keyBy('id');
        $batchTotals = $this->revenue->byBatch($period)->keyBy('id');
        $batches = Batch::query()
            ->with('course:id,code')
            ->whereKey($batchTotals->keys())
            ->get()
            ->keyBy('id');

        $rows = $batchTotals->map(function (array $batchRow) use ($batches, $courseTotals): array {
            $batch = $batches->get($batchRow['id']);

            if (! $batch instanceof Batch) {
                throw new LogicException('A revenue row has no batch carrier.');
            }

            /** @var array{id: int, code: string, total: Money}|null $courseRow */
            $courseRow = $courseTotals->get($batch->course_id);

            return [
                'carrier_id' => $batch->getKey(),
                'cells' => [
                    'course_code' => $courseRow['code'] ?? $batch->course->code,
                    'course_total' => $courseRow === null ? null : $courseRow['total']->toDecimal(),
                    'batch_code' => $batchRow['code'],
                    'batch_total' => $batchRow['total']->toDecimal(),
                ],
            ];
        })->values()->all();

        return new ReportDataset($this->columns('revenue', [
            'course_code', 'course_total', 'batch_code', 'batch_total',
        ]), $rows);
    }

    /** @param array<string, mixed> $options */
    private function outstanding(array $options): ReportDataset
    {
        $rows = $this->outstanding->asOf($this->requiredString($options, 'date'));
        $students = Student::withTrashed()
            ->whereKey($rows->pluck('student_id'))
            ->get()
            ->keyBy('id');

        return new ReportDataset($this->columns('outstanding', [
            'charge_reference', 'student_code', 'student_name', 'due_date',
            'days_past_due', 'bucket', 'outstanding',
        ]), $rows->map(function (array $row) use ($students): array {
            $student = $students->get($row['student_id']);

            return [
                'carrier_id' => $row['charge_id'],
                'cells' => [
                    'charge_reference' => $row['charge_reference'],
                    'student_code' => $student?->student_code,
                    'student_name' => $student?->full_name,
                    'due_date' => $row['due_date']->format('Y-m-d'),
                    'days_past_due' => $row['days_past_due'],
                    'bucket' => $row['bucket'],
                    'outstanding' => $row['outstanding']->toDecimal(),
                ],
            ];
        })->values()->all());
    }

    /** @param array<string, mixed> $options */
    private function paymentMethods(array $options): ReportDataset
    {
        return $this->tenderDataset(
            ReportKind::PaymentMethod,
            $this->tenders->forPeriod($this->period($options)),
        );
    }

    /** @param array<string, mixed> $options */
    private function dailyTender(array $options): ReportDataset
    {
        return $this->tenderDataset(
            ReportKind::DailyTender,
            $this->dailyTender->forDay($this->requiredString($options, 'date')),
        );
    }

    /**
     * @param  Collection<int, array{method: TenderMethod, total: Money}>  $totals
     */
    private function tenderDataset(ReportKind $kind, Collection $totals): ReportDataset
    {
        $representatives = PaymentTender::query()
            ->whereIn('method', $totals->pluck('method')->map(
                fn ($method): string => $method->value,
            ))
            ->selectRaw('MIN(id) as id, method')
            ->groupBy('method')
            ->get()
            ->keyBy(fn (PaymentTender $tender): string => $tender->method->value);

        $rows = $totals->map(function (array $row) use ($representatives): array {
            $carrier = $representatives->get($row['method']->value);

            if (! $carrier instanceof PaymentTender) {
                throw new LogicException('A tender report row has no source carrier.');
            }

            return [
                'carrier_id' => $carrier->getKey(),
                'cells' => [
                    'method' => $row['method']->label(),
                    'total' => $row['total']->toDecimal(),
                ],
            ];
        })->values()->all();

        return new ReportDataset($this->columns($kind->value, ['method', 'total']), $rows);
    }

    /** @param array<string, mixed> $options */
    private function wageCost(array $options): ReportDataset
    {
        [$year, $month] = $this->month($options);
        $rows = $this->wageCost->forMonth($year, $month);
        $users = User::withTrashed()->whereKey($rows->pluck('user_id'))->get()->keyBy('id');

        return new ReportDataset($this->columns('wage_cost', ['staff_name', 'total']),
            $rows->map(fn (array $row): array => [
                'carrier_id' => $row['user_id'],
                'cells' => [
                    'staff_name' => $users->get($row['user_id'])?->name,
                    'total' => $row['total']->toDecimal(),
                ],
            ])->values()->all());
    }

    /** @param array<string, mixed> $options */
    private function profit(array $options, User $requester): ReportDataset
    {
        [$year, $month] = $this->month($options);
        $totals = $this->profit->forMonth($year, $month);

        return new ReportDataset($this->columns('profit', ['revenue', 'wage_cost', 'profit']), [[
            'carrier_id' => $requester->getKey(),
            'cells' => [
                'revenue' => $totals['revenue']->toDecimal(),
                'wage_cost' => $totals['wageCost']->toDecimal(),
                'profit' => $totals['profit']->toDecimal(),
            ],
        ]]);
    }

    /** @param array<string, mixed> $options */
    private function studentHistory(array $options): ReportDataset
    {
        $columns = $this->columns('student_payment_history', [
            'student_code', 'student_name', 'charge_reference', 'charge_amount',
            'due_date', 'written_off', 'receipt_references', 'receipt_amounts', 'collected_total',
        ]);

        if (($options['student_id'] ?? null) === null || $options['student_id'] === '') {
            return new ReportDataset($columns, []);
        }

        $studentId = $this->requiredInt($options, 'student_id');
        $student = Student::withTrashed()->findOrFail($studentId);
        $history = $this->studentHistory->forStudent($studentId);
        $payments = $history['payments']->keyBy('id');
        $paymentIdsByCharge = PaymentAllocation::query()
            ->whereIn('payment_id', $payments->keys())
            ->get(['charge_id', 'payment_id'])
            ->groupBy('charge_id')
            ->map(fn (Collection $allocations): Collection => $allocations->pluck('payment_id'));

        $rows = $history['charges']->map(function (array $charge) use ($student, $payments, $paymentIdsByCharge, $history): array {
            $chargePayments = $payments->only($paymentIdsByCharge->get($charge['id'], collect()));

            return [
                'carrier_id' => $charge['id'],
                'cells' => [
                    'student_code' => $student->student_code,
                    'student_name' => $student->full_name,
                    'charge_reference' => $charge['reference'],
                    'charge_amount' => $charge['amount']->toDecimal(),
                    'due_date' => $charge['due_date']->format('Y-m-d'),
                    'written_off' => $charge['written_off_at'] === null ? __('reports.values.no') : __('reports.values.yes'),
                    'receipt_references' => $chargePayments->pluck('reference')->implode("\n"),
                    'receipt_amounts' => $chargePayments->map(fn (array $payment): string => $payment['total']->toDecimal())->implode("\n"),
                    'collected_total' => $history['collected_total']->toDecimal(),
                ],
            ];
        })->values()->all();

        return new ReportDataset($columns, $rows);
    }

    /** @param array<string, mixed> $options */
    private function period(array $options): ReportPeriod
    {
        return ReportPeriod::between(
            $this->requiredString($options, 'from'),
            $this->requiredString($options, 'to'),
        );
    }

    /** @param array<string, mixed> $options
     * @return array{int, int}
     */
    private function month(array $options): array
    {
        $value = $this->requiredString($options, 'month');

        if (preg_match('/^(?<year>\d{4})-(?<month>\d{2})$/D', $value, $matches) !== 1) {
            throw new InvalidArgumentException("Not a report month: [{$value}].");
        }

        $year = (int) $matches['year'];
        $month = (int) $matches['month'];
        ReportPeriod::month($year, $month);

        return [$year, $month];
    }

    /** @param array<string, mixed> $options */
    private function requiredString(array $options, string $key): string
    {
        $value = $options[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException("Missing report option [{$key}].");
        }

        return $value;
    }

    /** @param array<string, mixed> $options */
    private function requiredInt(array $options, string $key): int
    {
        $value = filter_var($options[$key] ?? null, FILTER_VALIDATE_INT);

        if (! is_int($value) || $value < 1) {
            throw new InvalidArgumentException("Invalid report option [{$key}].");
        }

        return $value;
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, string>
     */
    private function columns(string $report, array $keys): array
    {
        return collect($keys)->mapWithKeys(fn (string $key): array => [
            $key => (string) __(implode('.', ['reports', 'columns', $report, $key])),
        ])->all();
    }
}
