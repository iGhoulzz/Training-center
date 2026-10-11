<?php

declare(strict_types=1);

namespace App\Domain\Finance\Filament\Pages;

use App\Domain\Finance\Filament\Pages\Reports\DailyTenderReportPage;
use App\Domain\Finance\Filament\Pages\Reports\OutstandingAgedReportPage;
use App\Domain\Finance\Filament\Pages\Reports\PaymentMethodReportPage;
use App\Domain\Finance\Filament\Pages\Reports\ProfitReportPage;
use App\Domain\Finance\Filament\Pages\Reports\RevenueReportPage;
use App\Domain\Finance\Filament\Pages\Reports\StudentPaymentHistoryPage;
use App\Domain\Finance\Filament\Pages\Reports\WageCostReportPage;
use App\Domain\Finance\Filament\Resources\ChargeResource;
use App\Domain\Finance\Filament\Resources\DiscountResource;
use App\Domain\Finance\Filament\Resources\ExpenseCategoryResource;
use App\Domain\Finance\Filament\Resources\ExpenseResource;
use App\Domain\Finance\Filament\Resources\PaymentResource;
use App\Domain\Finance\Filament\Resources\PayrollRunResource;
use App\Domain\Finance\Filament\Resources\StaffCompensationResource;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Fluent;

/**
 * A landing page for finance: tiles that link to the finance screens and
 * reports which already exist. Navigation only, with no figures of its own and
 * no query of its own.
 *
 * A TILE APPEARS ONLY WHEN ITS DESTINATION WOULD OPEN FOR THIS VIEWER, AND THE
 * DESTINATION IS THE ONE WHO SAYS SO
 * ----------------------------------------------------------------------------
 * `TILES` is the only place a tile is defined: an id, a group and the
 * destination class. Whether the viewer sees it, and where it links, are both
 * asked of that class (`canAccess()` and `getUrl()`) at the moment the page is
 * built. The hub holds no permission name at all. That is the point: a copy of
 * "who may open the charges list" kept here would be correct on the day it was
 * written and silently wrong the first time the destination's rule changed,
 * either showing a tile that leads to a 403 or hiding one that would open.
 *
 * A TILE'S TITLE COMES FROM THE DESTINATION TOO
 * ---------------------------------------------
 * The same single-source reasoning: the title is `getNavigationLabel()` of the
 * destination, so the tile reads exactly as the destination's own menu entry
 * and page do, and renaming a screen renames its tile. Only the group headings
 * and each tile's one-line description are this page's own wording.
 *
 * `canAccess()` is asked rather than `canViewAny()` for a resource, because it
 * is what Filament itself consults before it serves the resource's index route
 * (`CanAuthorizeResourceAccess`), and a resource's `canAccess()` is `canViewAny()`
 * unless that resource has said otherwise. Asking the question the route asks
 * keeps a tile and its link from disagreeing.
 *
 * THE HUB OPENS FOR ANYONE WHO CAN SEE AT LEAST ONE TILE
 * ------------------------------------------------------
 * An empty hub would be a page with a title and nothing to do, so `canAccess()`
 * is true only when a tile is visible. Filament consults it on the route, on
 * every Livewire hydration, and before registering the navigation item, so a
 * viewer with no finance permission is refused with a 403 and sees no menu entry.
 * Reaching the panel at all (`access_admin_panel`) is the panel's business, not
 * the hub's.
 *
 * `extends Page`, NEVER `SimplePage`
 * ----------------------------------
 * The panel's `discoverPages()` call filters on `Page::class`, which is what
 * picks this class up without the panel provider being touched. See
 * `App\Filament\Pages\PasswordChange` for the full reason.
 */
final class FinanceHub extends Page
{
    /**
     * Every tile, in the order shown. The id names the tile's one-line
     * description (`finance_hub.tiles.<id>.description`); the group names the
     * heading it appears under (`finance_hub.groups.<group>.heading`), and the
     * groups are drawn in the order they first appear here. The tile's title is
     * not in this list or in the catalogue: it is the destination's own
     * navigation label.
     *
     * @var list<array{id: string, group: string, destination: class-string<\Filament\Resources\Resource>|class-string<Page>}>
     */
    private const TILES = [
        ['id' => 'charges', 'group' => 'money_in', 'destination' => ChargeResource::class],
        ['id' => 'payments', 'group' => 'money_in', 'destination' => PaymentResource::class],
        ['id' => 'discounts', 'group' => 'money_in', 'destination' => DiscountResource::class],
        ['id' => 'enroll_and_collect', 'group' => 'money_in', 'destination' => EnrollAndCollect::class],
        ['id' => 'payroll_runs', 'group' => 'money_out', 'destination' => PayrollRunResource::class],
        ['id' => 'staff_compensation', 'group' => 'money_out', 'destination' => StaffCompensationResource::class],
        ['id' => 'expenses', 'group' => 'money_out', 'destination' => ExpenseResource::class],
        ['id' => 'expense_categories', 'group' => 'money_out', 'destination' => ExpenseCategoryResource::class],
        ['id' => 'revenue_report', 'group' => 'reports', 'destination' => RevenueReportPage::class],
        ['id' => 'outstanding_aged_report', 'group' => 'reports', 'destination' => OutstandingAgedReportPage::class],
        ['id' => 'payment_method_report', 'group' => 'reports', 'destination' => PaymentMethodReportPage::class],
        ['id' => 'daily_tender_report', 'group' => 'reports', 'destination' => DailyTenderReportPage::class],
        ['id' => 'wage_cost_report', 'group' => 'reports', 'destination' => WageCostReportPage::class],
        ['id' => 'profit_report', 'group' => 'reports', 'destination' => ProfitReportPage::class],
        ['id' => 'student_payment_history_report', 'group' => 'reports', 'destination' => StudentPaymentHistoryPage::class],
    ];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    /**
     * The top of the ungrouped items. Filament gives an item with no sort of its
     * own -1 and the dashboard -2, and equal sorts fall back on registration
     * order, which nobody should rely on: -3 is the lowest number nothing else
     * holds. Navigation groups, and so where this sits among them, are the theme
     * and shell task's (P4-T16a); the hub joins none.
     */
    protected static ?int $navigationSort = -3;

    protected string $view = 'filament.finance.finance-hub';

    /**
     * Every tile the hub can show, whoever is looking.
     *
     * Public so FinanceHubTest can pin the list: a tile deleted or added is then
     * a deliberate change to a test, not an unnoticed one.
     *
     * @return list<array{id: string, group: string, destination: class-string<\Filament\Resources\Resource>|class-string<Page>}>
     */
    public static function tiles(): array
    {
        return self::TILES;
    }

    /**
     * The tiles whose destination would open for the current viewer.
     *
     * @return list<array{id: string, group: string, destination: class-string<\Filament\Resources\Resource>|class-string<Page>}>
     */
    public static function visibleTiles(): array
    {
        return array_values(array_filter(
            self::TILES,
            static fn (array $tile): bool => $tile['destination']::canAccess(),
        ));
    }

    public static function canAccess(): bool
    {
        return self::visibleTiles() !== [];
    }

    public function getTitle(): string
    {
        return __('finance_hub.title');
    }

    public static function getNavigationLabel(): string
    {
        return __('finance_hub.navigation_label');
    }

    /**
     * The visible tiles gathered under their group headings, in display order.
     * A group with no visible tile is simply absent.
     *
     * Objects rather than arrays so the view reads `$group->heading` and not
     * `$group['heading']`: LocalizationTest's Blade detector reads a quoted key
     * inside a bound `:heading="..."` attribute as a hardcoded string, which it
     * is not.
     *
     * @return array{groups: list<Fluent<string, mixed>>}
     */
    protected function getViewData(): array
    {
        $groups = [];

        foreach (self::visibleTiles() as $tile) {
            $groups[$tile['group']] ??= [
                'id' => $tile['group'],
                'heading' => __("finance_hub.groups.{$tile['group']}.heading"),
                'description' => __("finance_hub.groups.{$tile['group']}.description"),
                'tiles' => [],
            ];

            $groups[$tile['group']]['tiles'][] = new Fluent([
                'id' => $tile['id'],
                'label' => $tile['destination']::getNavigationLabel(),
                'description' => __("finance_hub.tiles.{$tile['id']}.description"),
                'url' => self::urlOf($tile['destination']),
            ]);
        }

        return ['groups' => array_map(
            static fn (array $group): Fluent => new Fluent($group),
            array_values($groups),
        )];
    }

    /**
     * Where a destination lives. A resource is reached through its index page,
     * a page through its own address; Filament names them differently.
     *
     * @param  class-string<\Filament\Resources\Resource>|class-string<Page>  $destination
     */
    private static function urlOf(string $destination): string
    {
        return is_subclass_of($destination, Resource::class)
            ? $destination::getUrl('index')
            : $destination::getUrl();
    }
}
