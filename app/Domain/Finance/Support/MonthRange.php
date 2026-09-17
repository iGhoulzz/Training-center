<?php

declare(strict_types=1);

namespace App\Domain\Finance\Support;

use InvalidArgumentException;

/** A validated, inclusive range of whole local calendar months. */
final readonly class MonthRange
{
    private function __construct(
        public int $fromYear,
        public int $fromMonth,
        public int $toYear,
        public int $toMonth,
    ) {}

    /** @throws InvalidArgumentException if either month is invalid or the range is reversed. */
    public static function between(int $fromYear, int $fromMonth, int $toYear, int $toMonth): self
    {
        self::validateMonth($fromMonth);
        self::validateMonth($toMonth);

        if ([$toYear, $toMonth] < [$fromYear, $fromMonth]) {
            throw new InvalidArgumentException(
                "A month range ends before it starts: [{$fromYear}-{$fromMonth}] to [{$toYear}-{$toMonth}].",
            );
        }

        return new self($fromYear, $fromMonth, $toYear, $toMonth);
    }

    /** @throws InvalidArgumentException if $month is not 1-12. */
    public static function single(int $year, int $month): self
    {
        return self::between($year, $month, $year, $month);
    }

    /** The first local date in the range, inclusive. */
    public function firstLocalDate(): string
    {
        return self::monthStart($this->fromYear, $this->fromMonth);
    }

    /** The final local date in the range, inclusive. */
    public function lastLocalDate(): string
    {
        return sprintf(
            '%04d-%02d-%02d',
            $this->toYear,
            $this->toMonth,
            cal_days_in_month(CAL_GREGORIAN, $this->toMonth, $this->toYear),
        );
    }

    /** The first local date after the range, exclusive. */
    public function exclusiveEndLocalDate(): string
    {
        if ($this->toMonth === 12) {
            return self::monthStart($this->toYear + 1, 1);
        }

        return self::monthStart($this->toYear, $this->toMonth + 1);
    }

    /** @throws InvalidArgumentException if $month is not 1-12. */
    private static function validateMonth(int $month): void
    {
        if ($month < 1 || $month > 12) {
            throw new InvalidArgumentException("Not a calendar month: [{$month}]. Expected 1-12.");
        }
    }

    private static function monthStart(int $year, int $month): string
    {
        return sprintf('%04d-%02d-01', $year, $month);
    }
}
