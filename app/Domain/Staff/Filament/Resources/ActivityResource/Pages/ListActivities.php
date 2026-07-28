<?php

declare(strict_types=1);

namespace App\Domain\Staff\Filament\Resources\ActivityResource\Pages;

use App\Domain\Staff\Filament\Resources\ActivityResource;
use Filament\Resources\Pages\ListRecords;

/**
 * The only page the activity log has.
 *
 * NO HEADER ACTIONS, AND NO OTHER PAGES. There is no create page, no edit page
 * and no view page — an audit trail is read in place. Every page a resource
 * registers is another surface that would have to be proven incapable of
 * writing, and the cheapest way to prove that is not to have one.
 *
 * getHeaderActions() is not overridden: ListRecords returns an empty array by
 * default, and stating [] here would read as "someone considered adding one".
 */
class ListActivities extends ListRecords
{
    protected static string $resource = ActivityResource::class;
}
