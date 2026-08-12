<?php

declare(strict_types=1);

namespace App\Domain\Finance\Actions;

use App\Domain\Finance\Exceptions\DiscountInUseException;
use App\Domain\Finance\Models\Discount;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/** Delete a mistaken definition only while no issued bill references it. */
final class DeleteDiscountAction
{
    private const FOREIGN_KEY_RESTRICTED = 1451;

    public function __construct(private readonly CauserResolver $causers) {}

    public function execute(User $actor, Discount $discount): void
    {
        Gate::forUser($actor)->authorize('manage_pricing');

        try {
            $this->causers->withCauser(
                $actor,
                fn (): mixed => DB::transaction(fn () => $discount->delete()),
            );
        } catch (QueryException $exception) {
            if (($exception->errorInfo[1] ?? null) === self::FOREIGN_KEY_RESTRICTED) {
                throw new DiscountInUseException((int) $discount->getKey());
            }

            throw $exception;
        }
    }
}
