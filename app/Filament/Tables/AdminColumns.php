<?php

declare(strict_types=1);

namespace App\Filament\Tables;

use App\Support\CentreCalendar;
use Closure;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;

/**
 * The column shapes the admin lists repeat (P4-T41), so each is drawn one way.
 *
 * Each builder returns an ordinary `TextColumn`: the page chains `searchable()`,
 * `sortable()`, `url()`, `visibleFrom()` and the rest exactly as it did before, and
 * the column keeps the name and state it always had. A shape lives here only when
 * at least two pages use it.
 *
 * Money is rendered from the decimal STRING the database or the `Money` cast hands
 * over, through the domain's own `amount_lyd` key, and never passes through a
 * float.
 */
final class AdminColumns
{
    /**
     * A record's name, in the strong weight, with an optional muted line beneath.
     *
     * @param  Closure|string|null  $secondary  The muted line under the name, or a closure producing it.
     */
    public static function title(string $name, string $label, Closure|string|null $secondary = null): TextColumn
    {
        return TextColumn::make($name)
            ->label($label)
            ->weight('semibold')
            ->description($secondary);
    }

    /**
     * A person (a student, a member of staff, an instructor): the avatar, the name and
     * a muted line beneath, drawn by the `admin.person` component.
     *
     * @param  Closure  $data  Receives the record and returns `name`, `identity` (the avatar's seed),
     *                         `secondary`, `url` and optionally `size` for the component.
     */
    public static function person(string $name, string $label, Closure $data): ViewColumn
    {
        return ViewColumn::make($name)
            ->label($label)
            ->view('filament.enrollment.columns.person')
            ->viewData($data);
    }

    /** An identifier or code: monospace and muted. */
    public static function code(string $name, string $label): TextColumn
    {
        return TextColumn::make($name)
            ->label($label)
            ->fontFamily(FontFamily::Mono)
            ->size('xs')
            ->color('gray');
    }

    /** Muted supporting text. */
    public static function secondary(string $name, string $label): TextColumn
    {
        return TextColumn::make($name)
            ->label($label)
            ->color('gray');
    }

    /** The person who did something: a relation's name, muted. */
    public static function actor(string $name, string $label): TextColumn
    {
        return self::secondary($name, $label);
    }

    /**
     * An amount of money: end-aligned, tabular figures, never wrapped.
     *
     * @param  string  $amountKey  The domain's `amount_lyd` lang key, for example `charges.amount_lyd`.
     */
    public static function money(string $name, string $label, string $amountKey): TextColumn
    {
        return TextColumn::make($name)
            ->label($label)
            ->alignEnd()
            ->formatStateUsing(fn (mixed $state): ?string => $state === null
                ? null
                : __($amountKey, ['amount' => (string) $state]))
            ->extraAttributes(['class' => 'whitespace-nowrap tabular-nums']);
    }

    /**
     * A status shown as a badge.
     *
     * @param  Closure|null  $format  Turns the state (often an enum) into its label.
     * @param  Closure|string  $color  A Filament colour name, or a closure choosing one.
     */
    public static function status(string $name, string $label, ?Closure $format = null, Closure|string $color = 'gray'): TextColumn
    {
        return TextColumn::make($name)
            ->label($label)
            ->badge()
            ->when($format !== null, fn (TextColumn $column): TextColumn => $column->formatStateUsing($format))
            ->color($color);
    }

    /**
     * A day. A timestamp shown as a day names the centre's zone itself, because a
     * bare `date()` is not reached by the global display zone (P4-T34); a column
     * that already holds a plain date needs no zone.
     */
    public static function date(string $name, string $label, bool $isTimestamp = false): TextColumn
    {
        $column = TextColumn::make($name)->label($label);

        return $isTimestamp
            ? $column->date(timezone: CentreCalendar::TIMEZONE)
            : $column->date();
    }

    /** A moment, in the centre's display zone. */
    public static function dateTime(string $name, string $label): TextColumn
    {
        return TextColumn::make($name)
            ->label($label)
            ->dateTime(timezone: CentreCalendar::TIMEZONE);
    }
}
