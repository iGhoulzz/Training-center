<?php

declare(strict_types=1);

namespace App\Domain\Finance\Actions;

use App\Domain\Finance\Models\Discount;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/** The definition's sole lifecycle transition; issued bills do not move. */
final class DeactivateDiscountAction
{
    public function __construct(private readonly CauserResolver $causers) {}

    public function execute(User $actor, Discount $discount): void
    {
        Gate::forUser($actor)->authorize('manage_pricing');

        if (! $discount->is_active) {
            return;
        }

        $this->causers->withCauser(
            $actor,
            fn (): bool => $discount->update(['is_active' => false]),
        );
    }
}
