<?php

declare(strict_types=1);

namespace App\Domain\Finance\Filament\Resources\DiscountResource\Pages;

use App\Domain\Finance\Filament\Resources\DiscountResource;
use Filament\Resources\Pages\ViewRecord;

final class ViewDiscount extends ViewRecord
{
    protected static string $resource = DiscountResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
