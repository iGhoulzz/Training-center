<?php

declare(strict_types=1);

namespace App\Domain\Finance\Actions;

use App\Domain\Enrollment\Models\Batch;
use App\Domain\Finance\Services\PricingService;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/** The only application writer of `batches.price`. */
final class UpdateBatchPriceAction
{
    public function __construct(
        private readonly PricingService $pricing,
        private readonly CauserResolver $causers,
    ) {}

    public function execute(User $actor, Batch $batch, ?string $price): void
    {
        Gate::forUser($actor)->authorize('manage_pricing');

        $this->causers->withCauser(
            $actor,
            fn (): bool => $batch->update([
                'price' => $this->pricing->normalizeNullablePrice($price),
            ]),
        );
    }
}
