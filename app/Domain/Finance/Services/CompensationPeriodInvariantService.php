<?php

declare(strict_types=1);

namespace App\Domain\Finance\Services;

use App\Domain\Finance\Enums\CompensationType;
use App\Domain\Finance\Models\StaffCompensation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/** Owns the locked, per-person and per-type compensation timeline invariant. */
final class CompensationPeriodInvariantService
{
    /**
     * Lock the stable parent and return the open row that a valid change closes.
     */
    public function lockAndFindPrevious(
        int $userId,
        CompensationType $type,
        CarbonImmutable $effectiveFrom,
    ): ?StaffCompensation {
        User::query()->whereKey($userId)->lockForUpdate()->firstOrFail();

        /** @var Collection<int, StaffCompensation> $timeline */
        $timeline = StaffCompensation::query()
            ->where('user_id', $userId)
            ->where('type', $type->value)
            ->orderBy('effective_from')
            ->get();

        $openRows = $timeline->filter(
            fn (StaffCompensation $rate): bool => $rate->effective_to === null,
        );

        if ($openRows->count() > 1) {
            $this->rejectOverlap();
        }

        $previous = $openRows->first();

        if ($previous instanceof StaffCompensation
            && $previous->effective_from->greaterThanOrEqualTo($effectiveFrom)
        ) {
            $this->rejectOverlap();
        }

        $conflict = $timeline->contains(function (StaffCompensation $rate) use ($previous, $effectiveFrom): bool {
            if ($previous instanceof StaffCompensation && $rate->is($previous)) {
                return false;
            }

            return $rate->effective_to === null
                || $rate->effective_to->greaterThanOrEqualTo($effectiveFrom);
        });

        if ($conflict) {
            $this->rejectOverlap();
        }

        return $previous;
    }

    /** @throws ValidationException */
    private function rejectOverlap(): never
    {
        throw ValidationException::withMessages([
            'effective_from' => __('payroll.compensation_period_overlap'),
        ]);
    }
}
