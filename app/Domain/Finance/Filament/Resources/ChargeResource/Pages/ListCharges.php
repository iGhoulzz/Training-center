<?php

declare(strict_types=1);

namespace App\Domain\Finance\Filament\Resources\ChargeResource\Pages;

use App\Domain\Finance\Filament\Resources\ChargeResource;
use Filament\Resources\Pages\ListRecords;

/**
 * The bills, listed. Read-plus-two-actions — see `ChargeResource`'s own
 * docblock.
 *
 * NO HEADER ACTIONS, AND NO CREATE PAGE TO LINK TO.
 * --------------------------------------------------
 * `ChargePolicy::create()` refuses unconditionally (design section 4) and
 * `ChargeResource::canCreate()` says so explicitly. `getHeaderActions()` is not
 * overridden for the same reason `ActivityResource`'s `ListActivities` leaves
 * it alone: `ListRecords` returns an empty array by default, and writing `[]`
 * here would read as "someone considered adding one".
 */
class ListCharges extends ListRecords
{
    protected static string $resource = ChargeResource::class;
}
