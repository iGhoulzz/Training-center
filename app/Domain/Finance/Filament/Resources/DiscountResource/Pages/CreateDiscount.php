<?php

declare(strict_types=1);

namespace App\Domain\Finance\Filament\Resources\DiscountResource\Pages;

use App\Domain\Finance\Actions\CreateDiscountAction;
use App\Domain\Finance\Filament\Resources\DiscountResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/** Creates through the self-authorizing Action, never generic persistence. */
final class CreateDiscount extends CreateRecord
{
    protected static string $resource = DiscountResource::class;

    protected ?bool $hasDatabaseTransactions = true;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        /** @var User $actor */
        $actor = auth()->user();

        return app(CreateDiscountAction::class)->execute(
            $actor,
            (string) $data['name'],
            (string) $data['percentage'],
        );
    }
}
