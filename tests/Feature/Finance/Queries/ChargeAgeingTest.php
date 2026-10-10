<?php

declare(strict_types=1);

use App\Domain\Finance\Models\Charge;
use App\Domain\Finance\Models\PaymentAllocation;
use App\Domain\Finance\Queries\ChargeAgeing;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('supplies every ageing boundary and omits paid and written-off chips', function () {
    $expected = [];
    foreach ([-1 => '0-30', 0 => '0-30', 30 => '0-30', 31 => '31-60', 60 => '31-60', 61 => '61-90', 90 => '61-90', 91 => '91+'] as $days => $bucket) {
        $charge = Charge::factory()->create(['due_date' => CarbonImmutable::parse('2026-10-10')->subDays($days)->toDateString()]);
        $expected[$charge->id] = $bucket;
    }
    Charge::factory()->writtenOff()->create();
    $paid = Charge::factory()->create(['amount' => '1.000']);
    PaymentAllocation::factory()->for($paid, 'charge')->create(['amount' => '1.000']);

    expect(app(ChargeAgeing::class)->asOf('2026-10-10')->all())->toBe($expected);
});

it('loads one or twenty chips in one statement', function () {
    $counts = [];
    foreach ([1, 20] as $size) {
        Charge::factory()->count($size === 1 ? 1 : 19)->create();
        DB::enableQueryLog();
        DB::flushQueryLog();
        expect(app(ChargeAgeing::class)->asOf('2026-10-10'))->toHaveCount($size);
        $counts[] = count(DB::getQueryLog());
        DB::disableQueryLog();
    }
    expect($counts)->toBe([1, 1]);
});

it('refuses an impossible as-of date', function () {
    expect(fn () => app(ChargeAgeing::class)->asOf('2026-02-30'))->toThrow(InvalidArgumentException::class);
});
