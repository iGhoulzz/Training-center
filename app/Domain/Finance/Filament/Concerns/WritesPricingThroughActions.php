<?php

declare(strict_types=1);

namespace App\Domain\Finance\Filament\Concerns;

use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Course;
use App\Domain\Finance\Actions\UpdateBatchPriceAction;
use App\Domain\Finance\Actions\UpdateCoursePriceAction;
use App\Domain\Finance\Services\PricingService;
use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Persists the two always-non-dehydrated price fields through their Actions.
 *
 * The ability check intentionally happens before raw form state is read. An
 * admin has no price field, so crafted state is ignored rather than passed into
 * an Action whose refusal would roll back an otherwise valid catalogue edit.
 */
trait WritesPricingThroughActions
{
    protected function writeCoursePrice(): void
    {
        $actor = $this->pricingActor();

        if (! $actor->can('manage_pricing')) {
            return;
        }

        $rawPrice = $this->rawPricingState()['default_price'] ?? null;

        if (! is_string($rawPrice) && ! is_int($rawPrice)) {
            return;
        }

        $price = (string) $rawPrice;
        $course = $this->courseRecord();

        if (! app(PricingService::class)->coursePriceChanged($course, $price)) {
            return;
        }

        try {
            app(UpdateCoursePriceAction::class)->execute($actor, $course, $price);
        } catch (AuthorizationException) {
            $this->refusePricingSave();
        }
    }

    protected function writeBatchPrice(): void
    {
        $actor = $this->pricingActor();

        if (! $actor->can('manage_pricing')) {
            return;
        }

        $rawPrice = $this->rawPricingState()['price'] ?? null;
        $price = is_string($rawPrice) || is_int($rawPrice) ? (string) $rawPrice : null;
        $batch = $this->batchRecord();

        if (! app(PricingService::class)->batchPriceChanged($batch, $price)) {
            return;
        }

        try {
            app(UpdateBatchPriceAction::class)->execute($actor, $batch, $price);
        } catch (AuthorizationException) {
            $this->refusePricingSave();
        }
    }

    protected function refusePricingSave(): never
    {
        Notification::make()
            ->title(__('pricing.save_refused'))
            ->danger()
            ->persistent()
            ->send();

        throw (new Halt)->rollBackDatabaseTransaction();
    }

    /**
     * @return array<string, mixed>
     */
    private function rawPricingState(): array
    {
        $state = $this->form->getRawState();

        return is_array($state) ? $state : $state->toArray();
    }

    private function pricingActor(): User
    {
        /** @var User $actor */
        $actor = auth()->user();

        return $actor;
    }

    private function courseRecord(): Course
    {
        /** @var Course $record */
        $record = $this->record;

        return $record;
    }

    private function batchRecord(): Batch
    {
        /** @var Batch $record */
        $record = $this->record;

        return $record;
    }
}
