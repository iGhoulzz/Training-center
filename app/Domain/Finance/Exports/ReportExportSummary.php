<?php

declare(strict_types=1);

namespace App\Domain\Finance\Exports;

use App\Domain\Finance\Support\Money;
use Illuminate\Support\Facades\Lang;
use LogicException;

/** One report-specific amount derived solely from the frozen export rows. */
final readonly class ReportExportSummary
{
    private function __construct(
        public string $label,
        public string $amount,
    ) {}

    public static function fromSnapshot(ReportSnapshot $snapshot): self
    {
        $column = match ($snapshot->kind) {
            ReportKind::Revenue => 'batch_total',
            ReportKind::OutstandingAged => 'outstanding',
            ReportKind::PaymentMethod, ReportKind::DailyTender, ReportKind::WageCost => 'total',
            ReportKind::Profit => 'profit',
            ReportKind::StudentPaymentHistory => 'collected_total',
        };
        $total = Money::zero();

        foreach ($snapshot->dataset->rows as $row) {
            $value = $row['cells'][$column] ?? null;
            if (! is_string($value)) {
                throw new LogicException("A frozen report row has no [{$column}] amount.");
            }

            $total = $total->add(Money::fromDecimal($value));

            if ($snapshot->kind === ReportKind::StudentPaymentHistory) {
                break;
            }
        }

        return new self(
            (string) Lang::get('reports.export_totals.'.$snapshot->kind->value, [], $snapshot->locale),
            $total->toDecimal(),
        );
    }
}
