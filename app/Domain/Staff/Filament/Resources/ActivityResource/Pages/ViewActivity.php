<?php

declare(strict_types=1);

namespace App\Domain\Staff\Filament\Resources\ActivityResource\Pages;

use App\Domain\Staff\Filament\Resources\ActivityResource;
use App\Filament\Details\AdminDetails;
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
        return AdminDetails::schema($schema, [
            AdminDetails::section(__('activity.activity'), [
                AdminDetails::dateTime('created_at', __('activity.when')),

                AdminDetails::person('causer_name', __('activity.who'))
                    ->state(fn (Activity $record): string => ActivityResource::actorLabel($record)),

                AdminDetails::status('event', __('activity.action'))
                    ->state(fn (Activity $record): string => ActivityResource::eventLabel($record->event)),

                AdminDetails::text('subject_type', __('activity.record'))
                    ->state(fn (Activity $record): string => ActivityResource::subjectLabel($record)),

                AdminDetails::text('log_name', __('activity.log')),

                AdminDetails::text('properties.ip', __('activity.ip'))
                    ->placeholder(__('activity.no_ip')),
            ], wide: true),

            AdminDetails::section(__('activity.changes'), [
                AdminDetails::longText('attribute_changes', __('activity.changes'))
                    ->state(fn (Activity $record): string => ActivityResource::describeChanges($record))
                    ->extraAttributes(['class' => 'font-mono']),

                AdminDetails::longText('properties', __('activity.details'))
                    ->state(fn (Activity $record): string => ActivityResource::describeProperties($record))
                    ->extraAttributes(['class' => 'font-mono']),
            ], wide: true),
        ]);
    }
}
