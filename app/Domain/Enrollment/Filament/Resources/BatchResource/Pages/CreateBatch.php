<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Filament\Resources\BatchResource\Pages;

use App\Domain\Enrollment\Actions\CreateWithIdentifierCodeAction;
use App\Domain\Enrollment\Exceptions\IdentifierCodeAlreadyUsedException;
use App\Domain\Enrollment\Exceptions\IdentifierCodeExhaustedException;
use App\Domain\Enrollment\Filament\Resources\BatchResource;
use App\Domain\Enrollment\Models\Course;
use App\Domain\Finance\Filament\Concerns\WritesPricingThroughActions;
use App\Domain\Finance\Filament\Pages\EnrollAndCollect;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The batch row is inserted by CreateWithIdentifierCodeAction (P35-T09), which
 * generates a blank code; the always-non-dehydrated price is applied afterwards
 * through UpdateBatchPriceAction, once the returned batch exists. Instructor
 * assignment remains a separate Action-backed flow reached once the batch exists.
 *
 * Access is gated by CreateRecord::authorizeAccess(), which aborts 403 unless
 * the actor passes BatchPolicy::create() — which staff do not.
 */
class CreateBatch extends CreateRecord
{
    use WritesPricingThroughActions;

    protected static string $resource = BatchResource::class;

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

    protected function afterFill(): void
    {
        $courseId = filter_var(request()->query('course'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $course = $courseId === false ? null : Course::query()->active()->find($courseId);
        if ($course && Gate::allows('view', $course)) {
            $this->form->fill([...$this->data, 'course_id' => $course->getKey()]);
        }
    }

    /** @return array<Action|ActionGroup> */
    protected function getFormActions(): array
    {
        return [
            ...parent::getFormActions(),
            Action::make('createAndEnroll')->label(__('enrollment.create_and_enroll'))->color('gray')
                ->authorize(fn (): bool => BatchResource::canCreate() && auth()->user()->can('view_batch') && EnrollAndCollect::canAccess())
                ->action(function (): void {
                    $this->chainAfterCreation = true;
                    $this->create();
                }),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->chainAfterCreation
            ? EnrollAndCollect::getUrl(['batch' => $this->record->getKey()])
            : parent::getRedirectUrl();
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException when the typed code is taken or generation is exhausted.
     */
    protected function handleRecordCreation(array $data): Model
    {
        /** @var User $actor */
        $actor = auth()->user();

        try {
            return app(CreateWithIdentifierCodeAction::class)->createBatch($actor, $data);
        } catch (IdentifierCodeAlreadyUsedException|IdentifierCodeExhaustedException $exception) {
            throw ValidationException::withMessages([
                $this->form->getStatePath().'.code' => $exception->getMessage(),
            ]);
        }
    }

    protected function afterCreate(): void
    {
        $this->record->refresh();

        $this->writeBatchPrice();
    }
}
