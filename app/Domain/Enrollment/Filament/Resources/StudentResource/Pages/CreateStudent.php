<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Filament\Resources\StudentResource\Pages;

use App\Domain\Enrollment\Actions\CreateWithIdentifierCodeAction;
use App\Domain\Enrollment\Exceptions\IdentifierCodeAlreadyUsedException;
use App\Domain\Enrollment\Exceptions\IdentifierCodeExhaustedException;
use App\Domain\Enrollment\Filament\Resources\StudentResource;
use App\Domain\Finance\Filament\Pages\EnrollAndCollect;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Creates a student through CreateWithIdentifierCodeAction (P35-T09).
 *
 * This page used to persist with Filament's own bare insert, and its docblock
 * said a student record had "no Action-owned write behaviour". Generated codes
 * changed that: the code must be minted before the insert, from the same
 * instant the row's timestamps are written with, and a lost race on a typed
 * code must come back as a field error rather than a driver exception. That is
 * the Action's job, and handleRecordCreation() is where Filament hands it over.
 *
 * Access is still gated by CreateRecord::authorizeAccess(), which aborts 403
 * unless the actor passes StudentPolicy::create(); the Action re-authorizes the
 * same ability itself, because it is also reachable from Enrol & Collect.
 */
class CreateStudent extends CreateRecord
{
    protected static string $resource = StudentResource::class;

    /**
     * The type MUST be ?bool — Filament declares the property as ?bool in
     * Filament\Pages\Concerns\CanUseDatabaseTransactions, and narrowing it to
     * bool is a fatal incompatible-property-type error.
     */
    protected ?bool $hasDatabaseTransactions = true;

    protected bool $chainAfterCreation = false;

    protected function hasFullWidthFormActions(): bool
    {
        return true;
    }

    public function getFormContentComponent(): Component
    {
        return Form::make([EmbeddedSchema::make('form')])->id('form')->livewireSubmitHandler('create');
    }

    /** @return array<Action|ActionGroup> */
    protected function getFormActions(): array
    {
        return [
            ...parent::getFormActions(),
            Action::make('createAndEnroll')->label(__('enrollment.create_and_enroll'))->color('gray')
                ->authorize(fn (): bool => StudentResource::canCreate() && auth()->user()->can('view_student') && EnrollAndCollect::canAccess())
                ->action(function (): void {
                    $this->chainAfterCreation = true;
                    $this->create();
                }),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->chainAfterCreation
            ? EnrollAndCollect::getUrl(['student' => $this->record->getKey()])
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
            return app(CreateWithIdentifierCodeAction::class)->createStudent($actor, $data);
        } catch (IdentifierCodeAlreadyUsedException|IdentifierCodeExhaustedException $exception) {
            throw ValidationException::withMessages([
                $this->form->getStatePath().'.student_code' => $exception->getMessage(),
            ]);
        }
    }
}
