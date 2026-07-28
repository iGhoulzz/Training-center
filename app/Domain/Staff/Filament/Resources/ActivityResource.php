<?php

declare(strict_types=1);

namespace App\Domain\Staff\Filament\Resources;

use App\Domain\Staff\Filament\Resources\ActivityResource\Pages\ListActivities;
use App\Domain\Staff\Filament\Resources\ActivityResource\Pages\ViewActivity;
use App\Models\User;
use BackedEnum;
use Carbon\Carbon;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

/**
 * The audit trail, read-only (P1-T12).
 *
 * NOTHING HERE WRITES, AND NOTHING HERE CAN BE MADE TO WRITE
 * ----------------------------------------------------------
 * No record actions, no toolbar actions, no bulk actions, no create page, no
 * edit page. canCreate() is false and every mutation ability on ActivityPolicy
 * returns false, so even a crafted Livewire mount has nothing to reach.
 * ActivityAppendOnlyTest asserts the registry is empty rather than trusting this
 * comment — a control added later fails the build.
 *
 * The view page is read-only for the same reasons and is asserted to register no
 * actions either. It exists because the listing has to stay scannable: an entry's
 * full property set and change set do not fit a table row, and truncating them
 * left the explicit events — which roles, whose email — effectively invisible.
 *
 * READABLE, NOT RAW
 * -----------------
 * v5 stores field changes in `attribute_changes`, separate from `properties`.
 * Dumping either as JSON puts `{"old":{"status":"active"}}` in front of a
 * registrar. The changes column renders one translated line per field, and the
 * event and record-type columns resolve through lang keys so phase 4 translates
 * the log instead of showing `roles_changed` in an Arabic panel.
 *
 * @extends \Filament\Resources\Resource<Activity>
 */
class ActivityResource extends Resource
{
    protected static ?string $model = Activity::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    public static function getModelLabel(): string
    {
        return __('activity.activity');
    }

    public static function getPluralModelLabel(): string
    {
        return __('activity.activity_log');
    }

    public static function getNavigationLabel(): string
    {
        return __('activity.activity_log');
    }

    /**
     * The log is never created through the panel.
     *
     * Belt and braces with ActivityPolicy::create(), which also returns false.
     * Filament consults both, and the two disagreeing would be a bug worth
     * failing loudly rather than resolving silently.
     */
    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * Eager-load the causer, or the actor column is one query per row.
     *
     * @return Builder<Activity>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('causer');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('activity.when'))
                    ->dateTime()
                    ->sortable(),

                /*
                 * Null is meaningful and gets a word rather than a blank cell: a
                 * seeder, a scheduled job or a failed sign-in genuinely has no
                 * actor, and "System" says so. An empty cell reads as missing data.
                 */
                /*
                 * THREE DISTINCT ANSWERS, NOT TWO.
                 *
                 * No causer at all means the SYSTEM acted — a seeder, a console
                 * command, a failed sign-in. A causer whose account has since been
                 * soft-deleted is a PERSON, and rendering them as "System" would
                 * claim a machine did something a human did.
                 *
                 * The name comes from the snapshot taken at write time, so it
                 * survives both deletion and a later rename: the log says who the
                 * actor was then, not who that row is called now.
                 */
                TextColumn::make('causer_name')
                    ->label(__('activity.who'))
                    ->state(fn (Activity $record): string => self::actorLabel($record))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->where('properties->causer_name', 'like', '%'.$search.'%')),

                TextColumn::make('event')
                    ->label(__('activity.action'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => self::eventLabel($state)),

                /*
                 * The TYPE alone cannot identify anything. "Student" tells a
                 * reader nothing about which student, and an audit trail whose
                 * subject is unidentifiable answers half a question.
                 */
                TextColumn::make('subject_type')
                    ->label(__('activity.record'))
                    ->state(fn (Activity $record): string => self::subjectLabel($record)),

                /*
                 * One translated line per changed field, not a JSON blob.
                 * wrap() rather than limit(): a truncated audit entry is a reason
                 * to open something else, and there is nothing else to open.
                 */
                TextColumn::make('attribute_changes')
                    ->label(__('activity.changes'))
                    ->state(fn (Activity $record): string => self::describeChanges($record))
                    ->wrap(),

                /*
                 * The explicit events carry their substance HERE, not in
                 * attribute_changes: which roles were added or removed, the email
                 * a failed sign-in attempted, the title of a cascaded certificate.
                 * Without this column the panel says "Roles changed" and never
                 * says which roles, which is most of the answer missing.
                 *
                 * ip and causer_name are omitted — they have their own column and
                 * are context rather than detail.
                 */
                TextColumn::make('properties')
                    ->label(__('activity.details'))
                    ->state(fn (Activity $record): string => self::describeProperties($record))
                    ->wrap()
                    ->toggleable(),

                TextColumn::make('properties.ip')
                    ->label(__('activity.ip'))
                    ->placeholder(__('activity.no_ip'))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                /*
                 * NOT ->relationship('causer', 'name'). `causer` is a MorphTo, so
                 * Filament cannot resolve a single related table from it and falls
                 * back to selecting `name` from activity_log — a column that does
                 * not exist, which fails the whole page rather than just the
                 * filter.
                 *
                 * Every causer this application writes is a User; the query states
                 * that explicitly rather than matching causer_id alone, which
                 * would collide the moment phase 3 adds a second causer type.
                 */
                SelectFilter::make('causer_id')
                    ->label(__('activity.who'))
                    // withTrashed(): a departed member of staff is still an actor
                    // in the history, and dropping them from the filter would make
                    // their entries unreachable.
                    ->options(fn (): array => User::withTrashed()
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(
                            $data['value'] ?? null,
                            fn (Builder $q, string $causerId): Builder => $q
                                ->where('causer_type', User::class)
                                ->where('causer_id', $causerId),
                        )),

                /*
                 * Record type, offered as the labels a reader sees rather than as
                 * fully-qualified class names.
                 */
                SelectFilter::make('subject_type')
                    ->label(__('activity.record'))
                    ->options(fn (): array => Activity::query()
                        ->whereNotNull('subject_type')
                        ->distinct()
                        ->pluck('subject_type')
                        ->mapWithKeys(fn (string $type): array => [
                            $type => self::recordTypeLabel($type),
                        ])
                        ->all()),

                SelectFilter::make('log_name')
                    ->label(__('activity.log'))
                    ->options(fn (): array => Activity::query()
                        ->whereNotNull('log_name')
                        ->distinct()
                        ->pluck('log_name')
                        ->mapWithKeys(fn (string $log): array => [$log => $log])
                        ->all()),

                Filter::make('created_at')
                    ->schema([
                        DatePicker::make('from')->label(__('activity.from')),
                        DatePicker::make('until')->label(__('activity.until')),
                    ])
                    /*
                     * Timestamp comparisons, NOT whereDate().
                     *
                     * whereDate() wraps the column in DATE(), which makes the
                     * comparison non-sargable: MySQL cannot use the created_at
                     * index and scans the table instead — on the one table here
                     * that only ever grows. startOfDay/endOfDay keep the same
                     * inclusive meaning while leaving the column bare.
                     */
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(
                            $data['from'] ?? null,
                            fn (Builder $q, string $date): Builder => $q->where(
                                'created_at',
                                '>=',
                                Carbon::parse($date)->startOfDay(),
                            ),
                        )
                        ->when(
                            $data['until'] ?? null,
                            fn (Builder $q, string $date): Builder => $q->where(
                                'created_at',
                                '<=',
                                Carbon::parse($date)->endOfDay(),
                            ),
                        )),
            ])
            /*
             * The row links to the view page instead of carrying a view ACTION.
             * recordUrl() is navigation, not a mountable server-side handler, so
             * the registry stays empty and there is still nothing to reach.
             */
            ->recordUrl(fn (Activity $record): string => ActivityResource::getUrl('view', ['record' => $record]))
            // No recordActions, no toolbarActions, no bulk actions. Deliberate,
            // asserted, and the reason is the class docblock.
            ->recordActions([])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListActivities::route('/'),
            'view' => ViewActivity::route('/{record}'),
        ];
    }

    /**
     * Who acted — distinguishing "the system" from "a person whose account is gone".
     *
     * Reads the snapshot rather than the relation, so a soft-deleted or renamed
     * actor still shows the name recorded at the time. Falls back to a
     * deleted-account label when an entry has a causer id but no snapshot, which
     * is only possible for rows written before the snapshot existed.
     */
    public static function actorLabel(Activity $activity): string
    {
        if ($activity->causer_id === null) {
            return __('activity.system');
        }

        $name = $activity->getProperty('causer_name');

        if (! is_string($name) || $name === '') {
            return __('activity.unknown_actor');
        }

        // The account still exists: the snapshot is the historical name, which is
        // what the log should say.
        if ($activity->causer !== null) {
            return $name;
        }

        return __('activity.deleted_account', ['name' => $name]);
    }

    /** The subject as "Student #12" — the type alone identifies nothing. */
    public static function subjectLabel(Activity $activity): string
    {
        if ($activity->subject_type === null) {
            return __('activity.empty_value');
        }

        return __('activity.subject_reference', [
            'type' => self::recordTypeLabel($activity->subject_type),
            'id' => (string) $activity->subject_id,
        ]);
    }

    /**
     * The explicit events' substance: which roles, which email, which certificate.
     *
     * ip and causer_name are skipped — both have columns of their own and are
     * context rather than detail. Lists render comma-separated so "added: staff,
     * admin" reads as a sentence rather than as JSON.
     */
    public static function describeProperties(Activity $activity): string
    {
        $properties = $activity->properties;

        if (! $properties instanceof Collection) {
            return __('activity.empty_value');
        }

        $lines = $properties
            ->except(['ip', 'causer_name'])
            ->reject(fn (mixed $value): bool => $value === null
                || $value === ''
                || (is_array($value) && $value === []))
            ->map(fn (mixed $value, string $key): string => __('activity.property_line', [
                'key' => $key,
                'value' => is_array($value)
                    ? implode(', ', array_map(fn (mixed $item): string => (string) $item, $value))
                    : self::renderValue($value),
            ]));

        return $lines->isEmpty() ? __('activity.empty_value') : $lines->implode('
');
    }

    /** The translated label for a stored event, falling back to the raw value. */
    public static function eventLabel(?string $event): string
    {
        if ($event === null || $event === '') {
            return __('activity.empty_value');
        }

        $key = "activity.event.{$event}";
        $label = __($key);

        // A missing key returns the key itself; showing the bare event is more
        // useful than showing "activity.event.something".
        return $label === $key ? $event : $label;
    }

    /** The translated label for a subject class, keyed by basename. */
    public static function recordTypeLabel(?string $subjectType): string
    {
        if ($subjectType === null || $subjectType === '') {
            return __('activity.empty_value');
        }

        $basename = class_basename($subjectType);
        $key = "activity.record_type.{$basename}";
        $label = __($key);

        return $label === $key ? $basename : $label;
    }

    /**
     * Render v5's attribute_changes as one translated line per field.
     *
     * The column holds `['attributes' => [...], 'old' => [...]]`. A created
     * record has no `old`, so those fields render through a separate key rather
     * than as "— → value", which reads like a deletion happened first.
     */
    public static function describeChanges(Activity $activity): string
    {
        $changes = $activity->attribute_changes;

        if (! $changes instanceof Collection) {
            return __('activity.no_changes');
        }

        /** @var array<string, mixed> $newValues */
        $newValues = $changes->get('attributes') ?? [];

        /** @var array<string, mixed> $oldValues */
        $oldValues = $changes->get('old') ?? [];

        $new = collect($newValues);
        $old = collect($oldValues);

        if ($new->isEmpty() && $old->isEmpty()) {
            return __('activity.no_changes');
        }

        $fields = $new->keys()->merge($old->keys())->unique();

        return $fields
            ->map(function (string $field) use ($new, $old): string {
                $to = self::renderValue($new->get($field));

                if (! $old->has($field)) {
                    return __('activity.change_added', ['field' => $field, 'to' => $to]);
                }

                return __('activity.change_line', [
                    'field' => $field,
                    'from' => self::renderValue($old->get($field)),
                    'to' => $to,
                ]);
            })
            ->implode("\n");
    }

    /** Scalars as text; anything structured as compact JSON; null as a dash. */
    public static function renderValue(mixed $value): string
    {
        if ($value === null || $value === '') {
            return __('activity.empty_value');
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return (string) json_encode($value);
    }
}
