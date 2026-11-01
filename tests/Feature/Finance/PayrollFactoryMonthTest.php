<?php

declare(strict_types=1);

use App\Domain\Finance\Models\PayrollLine;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * THE BUG THIS PINS. PayrollLineFactory took last month as `now()->subMonth()->startOfMonth()`.
 * Carbon subtracts a month with overflow, so on 31 October it lands on 31 September, which is
 * 1 October: the line's segment fell in the CURRENT month while PayrollRunFactory's default run
 * (`startOfMonth()->subMonths(1)`) fell in the previous one. Taking the start of the month first
 * cannot overflow.
 */
it('puts a salary line in the same month as the default run it belongs to, on any day of the month', function (string $now, string $month) {
    $this->travelTo(CarbonImmutable::parse($now, 'Africa/Tripoli'));

    $line = PayrollLine::factory()->create();

    // Written out by hand: the month before the pinned "today", in the centre's zone.
    expect($line->run->period_start->toDateString())->toBe($month.'-01')
        ->and($line->segment_start->toDateString())->toBe($month.'-01')
        ->and($line->segment_end->format('Y-m'))->toBe($month);
})->with([
    'the 31st of October' => ['2026-10-31 12:00:00', '2026-09'],
    'the 30th of March, before a 28-day February' => ['2026-03-30 12:00:00', '2026-02'],
    'the 29th of May' => ['2026-05-29 12:00:00', '2026-04'],
    'the 1st' => ['2026-10-01 12:00:00', '2026-09'],
]);
