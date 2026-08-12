<?php

declare(strict_types=1);

namespace App\Domain\Finance\Actions;

use App\Domain\Finance\Models\Discount;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Spatie\Activitylog\Support\CauserResolver;

/** The only application path that defines a reusable discount. */
final class CreateDiscountAction
{
    public function __construct(private readonly CauserResolver $causers) {}

    public function execute(User $actor, string $name, string $percentage): Discount
    {
        Gate::forUser($actor)->authorize('manage_pricing');

        $validated = Validator::make([
            'name' => trim($name),
            'percentage' => trim($percentage),
        ], [
            'name' => ['required', 'string', 'max:150', Rule::unique(Discount::class, 'name')],
            'percentage' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'lte:100'],
        ])->validate();

        return $this->causers->withCauser(
            $actor,
            fn (): Discount => Discount::create([
                'name' => (string) $validated['name'],
                'percentage' => (string) $validated['percentage'],
                'is_active' => true,
            ]),
        );
    }
}
