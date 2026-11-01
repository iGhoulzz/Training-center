<?php

declare(strict_types=1);

namespace App\Filament\Details;

use App\Support\CentreCalendar;
use Closure;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;

/**
 * The one shape every admin detail (View) page is built on (P4-T42, owner decision 2026-11-01).
 *
 * A detail page is read-only text in section cards, never a form with its inputs greyed
 * out: a disabled form looks like something you could type in, and the pages that used
 * one sat beside pages that used an infolist, so two screens of the same kind looked
 * like two products. `AdminDetailsArchTest` fails a View page that builds its schema any
 * other way.
 *
 * WHAT A PAGE STILL OWNS: its sections, which entries each holds, every `visible()`
 * rule (a sensitive figure stays behind the permission it was behind), its header
 * actions, tabs and relation managers. This class adds only the layout and the entry
 * shapes the pages repeat, and changes no query.
 *
 * LAYOUT. The page is a grid that is one column on a phone and two from `lg`. A section
 * is a full-width card; a `wide` one spans both columns, the rest sit side by side. Inside
 * a card the entries are one column on a phone and two from `sm`. The look is CSS under
 * one prefix, `.tc-details` in resources/css/filament/admin/theme.css, so a page never
 * hand-styles an entry, and every inline measure there is logical (correct in RTL).
 *
 * EMPTY VALUES show a muted dash, never a blank, so a missing value reads as missing and
 * not as a rendering fault.
 *
 * Every builder returns an ordinary `TextEntry`: the page chains `url()`, `visible()`,
 * `state()` and the rest as it needs.
 */
final class AdminDetails
{
    /** The class on the page's schema. theme.css styles everything beneath it. */
    public const CSS_CLASS = 'tc-details';

    /**
     * The page's schema: a grid of section cards.
     *
     * It mutates and returns the schema it is given, because Filament ignores the
     * return value of `Resource::infolist()`; return it straight away.
     *
     * @param  array<int, mixed>  $components
     */
    public static function schema(Schema $schema, array $components): Schema
    {
        return $schema
            ->columns(['default' => 1, 'lg' => 2])
            ->extraAttributes(['class' => self::CSS_CLASS])
            ->components($components);
    }

    /**
     * One card.
     *
     * @param  array<int, mixed>  $entries
     * @param  bool  $wide  Span the whole row instead of sharing it with the next card.
     */
    public static function section(string $heading, array $entries, ?string $description = null, bool $wide = false): Section
    {
        return Section::make($heading)
            ->description($description)
            ->columns(['default' => 1, 'sm' => 2])
            ->columnSpan($wide ? 'full' : 1)
            ->schema($entries);
    }

    /** Plain text. */
    public static function text(string $name, string $label): TextEntry
    {
        return TextEntry::make($name)
            ->label($label)
            ->placeholder(__('details.empty'));
    }

    /** A person or other actor named by a relation, for example `recordedBy.name`. */
    public static function person(string $name, string $label): TextEntry
    {
        return self::text($name, $label);
    }

    /** An identifier or code: monospace. */
    public static function code(string $name, string $label): TextEntry
    {
        return self::text($name, $label)
            ->fontFamily(FontFamily::Mono);
    }

    /**
     * Free text that may run to several lines: it keeps its line breaks and spans the card.
     *
     * The breaks are made with `<br>` from the escaped text, not with `white-space: pre-line`,
     * which would also keep the template's own indentation as blank lines.
     */
    public static function longText(string $name, string $label): TextEntry
    {
        return self::text($name, $label)
            ->columnSpanFull()
            ->html()
            ->formatStateUsing(fn (mixed $state): ?string => filled($state) ? nl2br(e((string) $state)) : null)
            ->extraAttributes(['class' => 'break-words']);
    }

    /**
     * An amount of money: tabular figures, never wrapped, from the decimal string.
     *
     * @param  string  $amountKey  The domain's `amount_lyd` lang key, for example `charges.amount_lyd`.
     */
    public static function money(string $name, string $label, string $amountKey): TextEntry
    {
        return self::text($name, $label)
            ->formatStateUsing(fn (mixed $state): ?string => AdminFormat::money($amountKey, $state))
            ->extraAttributes(['class' => 'whitespace-nowrap tabular-nums']);
    }

    /**
     * A status shown as a badge.
     *
     * @param  Closure|null  $format  Turns the state (often an enum) into its label.
     * @param  Closure|string  $color  A Filament colour name, or a closure choosing one.
     */
    public static function status(string $name, string $label, ?Closure $format = null, Closure|string $color = 'gray'): TextEntry
    {
        return self::text($name, $label)
            ->badge()
            ->when($format !== null, fn (TextEntry $entry): TextEntry => $entry->formatStateUsing($format))
            ->color($color);
    }

    /**
     * A day. A timestamp shown as a day names the centre's zone itself, because a
     * bare `date()` is not reached by the global display zone (P4-T34); a column
     * that already holds a plain date needs no zone.
     */
    public static function date(string $name, string $label, bool $isTimestamp = false): TextEntry
    {
        $entry = self::text($name, $label);

        return $isTimestamp
            ? $entry->date(timezone: CentreCalendar::TIMEZONE)
            : $entry->date();
    }

    /** A moment, in the centre's display zone. */
    public static function dateTime(string $name, string $label): TextEntry
    {
        return self::text($name, $label)
            ->dateTime(timezone: CentreCalendar::TIMEZONE);
    }
}
