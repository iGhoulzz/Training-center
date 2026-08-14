<?php

declare(strict_types=1);

namespace App\Domain\Finance\Actions;

use App\Domain\Finance\Enums\CompensationType;
use App\Domain\Finance\Models\StaffCompensation;
use App\Domain\Finance\Services\CompensationPeriodInvariantService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Spatie\Activitylog\Support\CauserResolver;

/** The only application path for inserting an effective-dated compensation rate. */
final class ChangeCompensationAction
{
    public function __construct(
        private readonly CompensationPeriodInvariantService $periods,
        private readonly CauserResolver $causers,
    ) {}

    public function execute(
        User $actor,
        int $userId,
        CompensationType $type,
        string $amount,
        string $effectiveFrom,
    ): StaffCompensation {
        Gate::forUser($actor)->authorize('create', StaffCompensation::class);

        $validated = Validator::make([
            'amount' => trim($amount),
            'effective_from' => trim($effectiveFrom),
        ], [
            'amount' => ['required', 'numeric', 'decimal:0,3', 'gt:0', 'lte:999999999.999'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
        ])->validate();

        $start = CarbonImmutable::createFromFormat('!Y-m-d', (string) $validated['effective_from']);
        assert($start instanceof CarbonImmutable);

        return $this->causers->withCauser(
            $actor,
            fn (): StaffCompensation => DB::transaction(function () use (
                $userId,
                $type,
                $validated,
                $start,
            ): StaffCompensation {
                $previous = $this->periods->lockAndFindPrevious($userId, $type, $start);

                if ($previous instanceof StaffCompensation) {
                    $previous->update([
                        'effective_to' => $start->subDay()->toDateString(),
                    ]);
                }

                return StaffCompensation::create([
                    'user_id' => $userId,
                    'type' => $type,
                    'amount' => (string) $validated['amount'],
                    'effective_from' => $start->toDateString(),
                    'effective_to' => null,
                ]);
            }),
        );
    }
}
