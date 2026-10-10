<?php

declare(strict_types=1);

use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Finance\Models\Charge;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Models\PaymentAllocation;
use App\Domain\Finance\Queries\BatchOutstanding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('sums exact outstanding across students and keeps write-offs and reversed payments', function () {
    $batch = Batch::factory()->create();
    $first = Charge::factory()->for(Enrollment::factory()->for($batch))->create(['amount' => '123.456']);
    Charge::factory()->writtenOff()->for(Enrollment::factory()->for($batch))->create(['amount' => '0.001']);
    PaymentAllocation::factory()->for($first, 'charge')->create(['amount' => '23.455']);
    PaymentAllocation::factory()->for($first, 'charge')->for(Payment::factory()->reversed())->create(['amount' => '10.000']);
    $other = Batch::factory()->create();
    Charge::factory()->for(Enrollment::factory()->for($other))->create(['amount' => '7.777']);

    $totals = app(BatchOutstanding::class)->totals();
    expect($totals[$batch->id]->toDecimal())->toBe('100.002')
        ->and($totals[$other->id]->toDecimal())->toBe('7.777')
        ->and(app(BatchOutstanding::class)->forBatch($batch->id)->toDecimal())->toBe('100.002')
        ->and(app(BatchOutstanding::class)->forBatch($other->id)->toDecimal())->toBe('7.777')
        ->and(app(BatchOutstanding::class)->forBatch(999999)->toDecimal())->toBe('0.000');
});

it('aggregates one or twenty batches in one statement', function () {
    $counts = [];
    foreach ([1, 20] as $size) {
        Charge::factory()->count($size === 1 ? 1 : 19)->create();
        DB::enableQueryLog();
        DB::flushQueryLog();
        expect(app(BatchOutstanding::class)->totals())->toHaveCount($size);
        $counts[] = count(DB::getQueryLog());
        DB::disableQueryLog();
    }
    expect($counts)->toBe([1, 1]);
});
