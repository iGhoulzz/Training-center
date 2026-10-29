<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Date;

/**
 * The demo's single clock.
 *
 * Every area seeder REGISTERS what happened on which day; nothing writes while
 * registering. run() then sorts the whole history by date and executes it in
 * order, stepping the application clock to each event before it runs. That is
 * what lets one real Action chain (open a batch, enrol, bill, collect, complete,
 * certify) be replayed across six months with the timestamps, references and
 * activity-log rows the application itself would have produced on those days.
 *
 * THE CLOCK IS RESTORED IN ONE PLACE, AND THAT PLACE IS A finally BLOCK.
 * An Action that throws halfway through the history must not leave the process
 * believing it is still some day in April: a later write, a later test, or the
 * rest of an `artisan` session would silently stamp the wrong instant. The
 * previous test-now is captured first and put back exactly, so a caller that
 * had already pinned the clock gets its own value back rather than real time.
 *
 * NOTHING IS EVER STAMPED IN THE FUTURE. An event whose day is today (or
 * clamped to today) runs at the real instant the seed began, never at a later
 * hour of today that has not happened yet.
 */
final class DemoTimeline
{
    /** Minutes added to events that share a day and an hour, so their order shows in the log. */
    private const MAX_STAGGER_MINUTES = 50;

    /** @var list<array{at: CarbonImmutable, sequence: int, label: string, run: Closure(): void}> */
    private array $events = [];

    private int $sequence = 0;

    public function __construct(private readonly CarbonImmutable $realNow) {}

    /**
     * @param  CarbonImmutable  $day  A local (Africa/Tripoli) calendar day.
     * @param  int  $hour  The local hour of that day the event happens at.
     * @param  Closure(): void  $run
     */
    public function add(CarbonImmutable $day, int $hour, string $label, Closure $run): void
    {
        $this->events[] = [
            'at' => $day->setTime($hour, 0)->utc(),
            'sequence' => $this->sequence++,
            'label' => $label,
            'run' => $run,
        ];
    }

    public function count(): int
    {
        return count($this->events);
    }

    /**
     * Execute the whole history in date order.
     *
     * @throws \Throwable whatever an event throws, after the clock is restored.
     */
    public function run(): void
    {
        $previous = Date::getTestNow();

        usort($this->events, static fn (array $a, array $b): int => [$a['at']->getTimestamp(), $a['sequence']]
            <=> [$b['at']->getTimestamp(), $b['sequence']]);

        try {
            $slot = null;
            $stagger = 0;

            foreach ($this->events as $event) {
                $key = $event['at']->getTimestamp();
                $stagger = $slot === $key ? min($stagger + 1, self::MAX_STAGGER_MINUTES) : 0;
                $slot = $key;

                $instant = $event['at']->addMinutes($stagger);

                Date::setTestNow($instant->greaterThan($this->realNow) ? $this->realNow : $instant);

                ($event['run'])();
            }
        } finally {
            Date::setTestNow($previous);
        }
    }
}
