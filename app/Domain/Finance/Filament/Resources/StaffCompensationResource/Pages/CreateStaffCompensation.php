<?php

declare(strict_types=1);

namespace App\Domain\Finance\Filament\Resources\StaffCompensationResource\Pages;

use App\Domain\Finance\Actions\ChangeCompensationAction;
use App\Domain\Finance\Enums\CompensationType;
use App\Domain\Finance\Filament\Resources\StaffCompensationResource;
use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Support\Exceptions\Halt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Creates through the self-authorizing Action, never generic persistence.
 *
 * The Action refuses a date that overlaps or predates the employee's history,
 * and says so under the bare key `effective_from`. This form keeps its state
 * under `data`, so that key matches no field and the refusal rendered nowhere.
 */
final class CreateStaffCompensation extends CreateRecord
{
    protected static string $resource = StaffCompensationResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException when the Action refuses a value a form field shows.
     * @throws Halt when the refusal names no form field and is shown as a notification instead.
     */
    protected function handleRecordCreation(array $data): Model
    {
        /** @var User $actor */
        $actor = auth()->user();

        try {
            return app(ChangeCompensationAction::class)->execute(
                $actor,
                (int) $data['user_id'],
                CompensationType::from((string) $data['type']),
                (string) $data['amount'],
                (string) $data['effective_from'],
            );
        } catch (ValidationException $exception) {
            $this->showRefusal($exception);
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    /**
     * Re-key the refusal onto the form's state path so Filament shows it under
     * its field, and send any message no field owns as a persistent
     * notification, so a refusal can never be silent.
     *
     * Both exits roll the page's transaction back through CreateRecord.
     *
     * @throws ValidationException
     * @throws Halt
     */
    private function showRefusal(ValidationException $exception): never
    {
        $fields = $this->form->getFlatFields();
        $statePath = $this->form->getStatePath();
        $fieldErrors = [];

        foreach ($exception->errors() as $key => $messages) {
            if (array_key_exists($key, $fields)) {
                $fieldErrors["{$statePath}.{$key}"] = $messages;

                continue;
            }

            foreach ($messages as $message) {
                Notification::make()->title($message)->danger()->persistent()->send();
            }
        }

        if ($fieldErrors !== []) {
            throw ValidationException::withMessages($fieldErrors);
        }

        throw (new Halt)->rollBackDatabaseTransaction();
    }
}
