<?php

declare(strict_types=1);

namespace App\Filament\Tables;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * The one table every admin list is built on (P4-T41, owner decision 2026-10-31).
 *
 * A list is a real `<table>`: a header row, one column per fact, columns sized
 * from every row on the page so they cannot drift from row to row, sortable
 * headers where the data allows, and the same spacing, hover, empty state,
 * pagination and toolbar on every page. Filament's flexible row layout
 * (`Split`, `Stack`, `contentGrid`) has none of those: it draws no header and
 * sizes each row to its own content. `AdminTableArchTest` fails any list that
 * uses it, or that skips this entry point.
 *
 * WHAT A PAGE STILL OWNS: its columns, filters, tabs, search, actions, bulk
 * actions, record URL, default sort and empty-state wording. This class adds only
 * the look and the shared behaviour, and changes no query.
 *
 * THE LOOK IS CSS, UNDER ONE PREFIX. `.tc-table` in
 * resources/css/filament/admin/theme.css carries every rule, so a page never
 * hand-styles a table with utility classes.
 *
 * It mutates and returns the table it is given, because Filament ignores the
 * return value of `Resource::table()`; call it first and chain the rest.
 */
final class AdminTable
{
    /** The class on the table's root. theme.css styles everything beneath it. */
    public const CSS_CLASS = 'tc-table';

    /** The class on every body row. */
    public const ROW_CLASS = 'tc-table-row';

    /** Rows per page, offered the same on every list. */
    public const PAGE_OPTIONS = [10, 25, 50, 100];

    public const DEFAULT_PAGE_SIZE = 10;

    public static function configure(Table $table): Table
    {
        return $table
            ->extraAttributes(['class' => self::CSS_CLASS])
            ->recordClasses(self::ROW_CLASS)
            ->striped(false)
            ->paginated(self::PAGE_OPTIONS)
            ->defaultPaginationPageOption(self::DEFAULT_PAGE_SIZE)
            ->actionsColumnLabel(__('tables.actions'))
            ->emptyStateIcon(Heroicon::OutlinedInbox);
    }

    /**
     * A row's actions as one menu in the last column.
     *
     * A row offers the actions it may: a reversed expense has no "Reverse", a paid
     * bill no "Collect". Laid out as separate buttons, those rows would draw a
     * shorter or longer run than the rest and the buttons would slide sideways. One
     * menu button is the same width on every row, so the column stays aligned. The
     * menu is teleported, because the table's body scrolls and would clip it.
     *
     * @param  array<int, Action|ActionGroup>  $actions
     */
    public static function rowMenu(array $actions): ActionGroup
    {
        return ActionGroup::make($actions)
            ->label(__('tables.actions'))
            ->iconButton()
            ->dropdownTeleport();
    }
}
