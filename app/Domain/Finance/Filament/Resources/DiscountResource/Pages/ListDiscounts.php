<?php

declare(strict_types=1);

namespace App\Domain\Finance\Filament\Resources\DiscountResource\Pages;

use App\Domain\Finance\Filament\Resources\DiscountResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

final class ListDiscounts extends ListRecords
{
    protected static string $resource = DiscountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label(__('pricing.create_discount'))
                ->visible(fn (): bool => DiscountResource::canCreate())
                ->url(fn (): string => DiscountResource::getUrl('create')),
        ];
    }
}
