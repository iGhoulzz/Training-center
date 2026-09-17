<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Filament\Resources\BatchResource\Pages;

use App\Domain\Enrollment\Actions\CreateWithIdentifierCodeAction;
use App\Domain\Enrollment\Exceptions\IdentifierCodeAlreadyUsedException;
use App\Domain\Enrollment\Exceptions\IdentifierCodeExhaustedException;
use App\Domain\Enrollment\Filament\Resources\BatchResource;
use App\Domain\Finance\Filament\Concerns\WritesPricingThroughActions;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
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
