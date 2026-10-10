<?php

declare(strict_types=1);

use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Course;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Finance\Models\Charge;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Models\PaymentAllocation;
use App\Domain\Finance\Queries\RevenuePerCourse;
use App\Domain\Finance\Support\ReportPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('combines batches on cash basis with exact money and excludes reversals and the next period', function () {
    $course = Course::factory()->create();
    foreach ([['2026-09-30 22:00:00', '1.001', false], ['2026-10-20 12:00:00', '2.002', false], ['2026-10-31 22:00:00', '4.004', false], ['2026-10-15 12:00:00', '8.008', true]] as [$received, $amount, $reversed]) {
        $charge = Charge::factory()->for(Enrollment::factory()->for(Batch::factory()->for($course)))->create();
        $payment = Payment::factory()->state(['received_at' => $received]);
        if ($reversed) {
            $payment = $payment->reversed();
        }
        PaymentAllocation::factory()->for($charge, 'charge')->for($payment)->create(['amount' => $amount]);
    }
    $rows = app(RevenuePerCourse::class)->forPeriod(ReportPeriod::month(2026, 10));
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['id'])->toBe($course->id)
        ->and($rows[0]['code'])->toBe($course->code)
        ->and($rows[0]['total']->toDecimal())->toBe('3.003')
        ->and(app(RevenuePerCourse::class)->forPeriod(ReportPeriod::month(2025, 1)))->toBeEmpty();
});

it('groups one or twenty courses in one statement', function () {
    $counts = [];
    foreach ([1, 20] as $size) {
        PaymentAllocation::factory()->count($size === 1 ? 1 : 19)
            ->for(Payment::factory()->state(['received_at' => '2026-10-10 12:00:00']))->create();
        DB::enableQueryLog();
        DB::flushQueryLog();
        expect(app(RevenuePerCourse::class)->forPeriod(ReportPeriod::month(2026, 10)))->toHaveCount($size);
        $counts[] = count(DB::getQueryLog());
        DB::disableQueryLog();
    }
    expect($counts)->toBe([1, 1]);
});
