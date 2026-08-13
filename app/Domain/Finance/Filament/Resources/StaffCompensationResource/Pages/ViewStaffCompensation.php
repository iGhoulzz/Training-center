<?php

declare(strict_types=1);

namespace App\Domain\Finance\Filament\Resources\StaffCompensationResource\Pages;

use App\Domain\Finance\Filament\Resources\StaffCompensationResource;
use Filament\Resources\Pages\ViewRecord;

final class ViewStaffCompensation extends ViewRecord
{
    protected static string $resource = StaffCompensationResource::class;
}
