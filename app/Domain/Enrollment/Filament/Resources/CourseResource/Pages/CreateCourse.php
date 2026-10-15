<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Filament\Resources\CourseResource\Pages;

use App\Domain\Enrollment\Filament\Resources\BatchResource;
use App\Domain\Enrollment\Filament\Resources\CourseResource;
use App\Domain\Finance\Filament\Concerns\WritesPricingThroughActions;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;

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

    protected bool $chainAfterCreation = false;

    public function getFormContentComponent(): Component
    {
        return Form::make([EmbeddedSchema::make('form')])->id('form')->livewireSubmitHandler('create');
    }

    /** @return array<Action|ActionGroup> */
    protected function getFormActions(): array
    {
        return [
            ...parent::getFormActions(),
            Action::make('createAndBatch')->label(__('enrollment.create_and_batch'))->color('gray')
                ->authorize(fn (): bool => CourseResource::canCreate() && auth()->user()->can('view_course') && BatchResource::canAccess() && BatchResource::canCreate())
                ->action(function (): void {
                    $this->chainAfterCreation = true;
                    $this->create();
                }),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->chainAfterCreation
            ? BatchResource::getUrl('create', ['course' => $this->record->getKey()])
            : parent::getRedirectUrl();
    }

    protected function afterCreate(): void
    {
        $this->record->refresh();

        $this->writeCoursePrice();
    }
}
