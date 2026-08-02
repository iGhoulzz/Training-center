<?php

declare(strict_types=1);

namespace App\Domain\Staff\Filament\Resources\ActivityResource\Pages;

use App\Domain\Staff\Filament\Resources\ActivityResource;
use Filament\Resources\Pages\ListRecords;

/**
 * The activity log's listing page.
 *
 * NO HEADER ACTIONS, AND NO WRITE PAGES. There is no create page and no edit
 * page — every page a resource registers is another surface that would have to
 * be proven incapable of writing, and the cheapest way to prove that is not to
 * have one.
 *
 * ActivityResource::getPages() registers exactly two: this one and ViewActivity,
 * which is read-only and registers no actions of its own.
 *
 * An earlier version of this docblock said there was no view page either
 * (P1-T15, group 3 finding L9). It was written before that page existed and
 * never revisited, and the claim mattered rather more than most stale comments:
 * somebody auditing the write surface would have taken this file's word for it
 * and never opened the one page it forgot to mention.
 *
 * getHeaderActions() is not overridden: ListRecords returns an empty array by
 * default, and stating [] here would read as "someone considered adding one".
 */
class ListActivities extends ListRecords
{
    protected static string $resource = ActivityResource::class;
}
