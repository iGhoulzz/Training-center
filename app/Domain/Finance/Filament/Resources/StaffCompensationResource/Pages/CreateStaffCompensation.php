<?php

declare(strict_types=1);

namespace App\Domain\Finance\Filament\Resources\StaffCompensationResource\Pages;

use App\Domain\Finance\Actions\ChangeCompensationAction;
use App\Domain\Finance\Enums\CompensationType;
use App\Domain\Finance\Filament\Resources\StaffCompensationResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/** Creates through the self-authorizing Action, never generic persistence. */
final class CreateStaffCompensation extends CreateRecord
{
    protected static string $resource = StaffCompensationResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    /** @param array<string, mixed> $data */
    protected function handleRecordCreation(array $data): Model
    {
        /** @var User $actor */
        $actor = auth()->user();

        return app(ChangeCompensationAction::class)->execute(
            $actor,
            (int) $data['user_id'],
            CompensationType::from((string) $data['type']),
            (string) $data['amount'],
            (string) $data['effective_from'],
        );
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
