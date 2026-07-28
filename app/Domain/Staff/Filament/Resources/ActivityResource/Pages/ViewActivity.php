<?php

declare(strict_types=1);

namespace App\Domain\Staff\Filament\Resources\ActivityResource\Pages;

use App\Domain\Staff\Filament\Resources\ActivityResource;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;
use Spatie\Activitylog\Models\Activity;

/**
 * One entry in full, read-only.
 *
 * The listing has to stay scannable, so its detail columns wrap and truncate.
 * This is where an entry is actually read: the whole property set, the whole
 * change set, the subject's identity and the actor as recorded at the time.
 *
 * NO HEADER ACTIONS AND NO EDIT PAGE. ViewRecord adds an edit action of its own
 * when the resource has an edit page; this resource has none, so there is nothing
 * to inherit. ActivityAppendOnlyTest asserts the emptiness rather than trusting
 * that — a page that grew a "restore" button would otherwise ship quietly.
 */
class ViewActivity extends ViewRecord
{
    protected static string $resource = ActivityResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('created_at')
                ->label(__('activity.when'))
                ->dateTime(),

            TextEntry::make('causer_name')
                ->label(__('activity.who'))
                ->state(fn (Activity $record): string => ActivityResource::actorLabel($record)),

            TextEntry::make('event')
                ->label(__('activity.action'))
                ->state(fn (Activity $record): string => ActivityResource::eventLabel($record->event))
                ->badge(),

            TextEntry::make('subject_type')
                ->label(__('activity.record'))
                ->state(fn (Activity $record): string => ActivityResource::subjectLabel($record)),

            TextEntry::make('log_name')
                ->label(__('activity.log')),

            TextEntry::make('properties.ip')
                ->label(__('activity.ip'))
                ->placeholder(__('activity.no_ip')),

            TextEntry::make('attribute_changes')
                ->label(__('activity.changes'))
                ->state(fn (Activity $record): string => ActivityResource::describeChanges($record)),

            TextEntry::make('properties')
                ->label(__('activity.details'))
                ->state(fn (Activity $record): string => ActivityResource::describeProperties($record)),
        ]);
    }
}
