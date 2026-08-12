<?php

declare(strict_types=1);

namespace App\Domain\Finance\Actions;

use App\Domain\Enrollment\Models\Course;
use App\Domain\Finance\Services\PricingService;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Spatie\Activitylog\Support\CauserResolver;

/** The only application writer of `courses.default_price`. */
final class UpdateCoursePriceAction
{
    public function __construct(
        private readonly PricingService $pricing,
        private readonly CauserResolver $causers,
    ) {}

    public function execute(User $actor, Course $course, string $price): void
    {
        Gate::forUser($actor)->authorize('manage_pricing');

        $this->causers->withCauser(
            $actor,
            fn (): bool => $course->update([
                'default_price' => $this->pricing->normalizePrice($price),
            ]),
        );
    }
}
