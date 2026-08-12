<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Filament\Resources\CourseResource\Pages;

use App\Domain\Enrollment\Filament\Resources\CourseResource;
use App\Domain\Finance\Filament\Concerns\WritesPricingThroughActions;
use Filament\Resources\Pages\CreateRecord;

/**
 * Filament persists ordinary catalogue fields; the always-non-dehydrated price
 * is applied afterwards through UpdateCoursePriceAction. Access is gated by
 * CreateRecord::authorizeAccess(), which aborts 403 unless the actor passes
 * CoursePolicy::create().
 */
class CreateCourse extends CreateRecord
{
    use WritesPricingThroughActions;

    protected static string $resource = CourseResource::class;

    /**
     * The type MUST be ?bool — Filament declares the property as ?bool in
     * Filament\Pages\Concerns\CanUseDatabaseTransactions, and narrowing it to
     * bool is a fatal incompatible-property-type error.
     */
    protected ?bool $hasDatabaseTransactions = true;

    protected function afterCreate(): void
    {
        $this->record->refresh();

        $this->writeCoursePrice();
    }
}
