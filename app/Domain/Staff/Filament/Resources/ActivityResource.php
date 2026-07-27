<?php

declare(strict_types=1);

namespace App\Domain\Staff\Filament\Resources;

use App\Domain\Staff\Filament\Resources\ActivityResource\Pages\ListActivities;
use App\Models\User;
use BackedEnum;
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
 * There is deliberately no ViewActivity page either. Everything an entry holds
 * is on the row, and a view page is one more surface that would need the same
 * guarantees.
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
                TextColumn::make('causer.name')
                    ->label(__('activity.who'))
                    ->placeholder(__('activity.system'))
                    ->searchable(),

                TextColumn::make('event')
                    ->label(__('activity.action'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => self::eventLabel($state)),

                TextColumn::make('subject_type')
                    ->label(__('activity.record'))
                    ->formatStateUsing(fn (?string $state): string => self::recordTypeLabel($state)),

                /*
                 * One translated line per changed field, not a JSON blob.
                 * wrap() rather than limit(): a truncated audit entry is a reason
                 * to open something else, and there is nothing else to open.
                 */
                TextColumn::make('attribute_changes')
                    ->label(__('activity.changes'))
                    ->state(fn (Activity $record): string => self::describeChanges($record))
                    ->wrap(),

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
                    ->options(fn (): array => User::query()
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
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when(
                            $data['from'] ?? null,
                            fn (Builder $q, string $date): Builder => $q->whereDate('created_at', '>=', $date),
                        )
                        ->when(
                            $data['until'] ?? null,
                            fn (Builder $q, string $date): Builder => $q->whereDate('created_at', '<=', $date),
                        )),
            ])
            // No recordActions, no toolbarActions, no bulk actions. Deliberate,
            // asserted, and the reason is the class docblock.
            ->recordActions([])
            ->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListActivities::route('/'),
        ];
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
    private static function renderValue(mixed $value): string
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
