<?php

declare(strict_types=1);

use App\Domain\Finance\Filament\Pages\EnrollAndCollect;
use App\Domain\Finance\Filament\Pages\FinanceHub;
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
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The finance hub: a landing page tiling the finance screens and reports that
 * already exist, showing each tile only to someone who may open its destination.
 *
 * AUTHORIZATION IS TESTED AS AN ACTOR HOLDING EXACTLY THE DESTINATION'S
 * PERMISSION AND AS ONE HOLDING EVERY OTHER TILE PERMISSION BUT THAT ONE. Never
 * as a super admin: that role holds every permission, so it would pass whichever
 * ability the hub (or a destination) checked and prove nothing about which one.
 *
 * EVERY EXPECTED VALUE IS A LITERAL written in the table below: the destination
 * classes, the permission each one needs, the group it sits in, its label and its
 * address. Nothing is read back from the hub's own tile list, so a tile that was
 * deleted, added, regrouped, relabelled or pointed at the wrong page fails here
 * instead of agreeing with itself.
 */
uses(RefreshDatabase::class);

/*
 * The fifteen tiles, in the order the hub shows them. The permission is the one
 * the destination's own policy or page checks, written out by hand; the seven
 * reports share one because ReportPage::canAccess() does.
 */
const FINANCE_HUB_TILES = [
    'charges' => [
        'destination' => ChargeResource::class,
        'permission' => 'view_any_charge',
        'group' => 'money_in',
        'label' => 'Charges',
        'path' => '/admin/charges',
    ],
    'payments' => [
        'destination' => PaymentResource::class,
        'permission' => 'view_any_payment',
        'group' => 'money_in',
        'label' => 'Payments',
        'path' => '/admin/payments',
    ],
    'discounts' => [
        'destination' => DiscountResource::class,
        'permission' => 'view_any_discount',
        'group' => 'money_in',
        'label' => 'Discounts',
        'path' => '/admin/discounts',
    ],
    'enroll_and_collect' => [
        'destination' => EnrollAndCollect::class,
        'permission' => 'create_enrollment',
        'group' => 'money_in',
        'label' => 'Enrol & collect',
        'path' => '/admin/enroll-and-collect',
    ],
    'payroll_runs' => [
        'destination' => PayrollRunResource::class,
        'permission' => 'view_any_payroll_run',
        'group' => 'money_out',
        'label' => 'Payroll runs',
        'path' => '/admin/payroll-runs',
    ],
    'staff_compensation' => [
        'destination' => StaffCompensationResource::class,
        'permission' => 'view_any_staff_compensation',
        'group' => 'money_out',
        'label' => 'Staff compensation',
        'path' => '/admin/staff-compensations',
    ],
    'expenses' => [
        'destination' => ExpenseResource::class,
        'permission' => 'view_any_expense',
        'group' => 'money_out',
        'label' => 'Expenses',
        'path' => '/admin/expenses',
    ],
    'expense_categories' => [
        'destination' => ExpenseCategoryResource::class,
        'permission' => 'view_any_expense_category',
        'group' => 'money_out',
        'label' => 'Expense categories',
        'path' => '/admin/expense-categories',
    ],
    'revenue_report' => [
        'destination' => RevenueReportPage::class,
        'permission' => 'view_financial_report',
        'group' => 'reports',
        'label' => 'Revenue',
        'path' => '/admin/reports/revenue',
    ],
    'outstanding_aged_report' => [
        'destination' => OutstandingAgedReportPage::class,
        'permission' => 'view_financial_report',
        'group' => 'reports',
        'label' => 'Outstanding balances',
        'path' => '/admin/reports/outstanding-aged',
    ],
    'payment_method_report' => [
        'destination' => PaymentMethodReportPage::class,
        'permission' => 'view_financial_report',
        'group' => 'reports',
        'label' => 'Payment methods',
        'path' => '/admin/reports/payment-methods',
    ],
    'daily_tender_report' => [
        'destination' => DailyTenderReportPage::class,
        'permission' => 'view_financial_report',
        'group' => 'reports',
        'label' => 'Daily tender',
        'path' => '/admin/reports/daily-tender',
    ],
    'wage_cost_report' => [
        'destination' => WageCostReportPage::class,
        'permission' => 'view_financial_report',
        'group' => 'reports',
        'label' => 'Wage cost',
        'path' => '/admin/reports/wage-cost',
    ],
    'profit_report' => [
        'destination' => ProfitReportPage::class,
        'permission' => 'view_financial_report',
        'group' => 'reports',
        'label' => 'Profit',
        'path' => '/admin/reports/profit',
    ],
    'student_payment_history_report' => [
        'destination' => StudentPaymentHistoryPage::class,
        'permission' => 'view_financial_report',
        'group' => 'reports',
        'label' => 'Student payments',
        'path' => '/admin/reports/student-payment-history',
    ],
];

/*
 * The nine distinct permissions behind those fifteen tiles. Written out rather
 * than collected from the table above so that a tile whose permission was
 * mistyped in the table cannot quietly shrink this list as well.
 */
const FINANCE_HUB_PERMISSIONS = [
    'view_any_charge',
    'view_any_payment',
    'view_any_discount',
    'create_enrollment',
    'view_any_payroll_run',
    'view_any_staff_compensation',
    'view_any_expense',
    'view_any_expense_category',
    'view_financial_report',
];

dataset('finance hub tiles', function (): array {
    $rows = [];

    foreach (FINANCE_HUB_TILES as $id => $tile) {
        $rows[$id] = [$id, $tile];
    }

    return $rows;
});

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    // An actor holding exactly the named permissions and nothing else.
    $this->actorWith = function (string ...$permissions): User {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(...$permissions);

        return $user->fresh();
    };

    // The tile ids an actor holding the named permission is expected to see:
    // every row of the table above that needs it.
    $this->tileIdsNeeding = fn (string $permission): array => array_keys(
        array_filter(FINANCE_HUB_TILES, fn (array $tile): bool => $tile['permission'] === $permission),
    );

    /*
     * What the hub actually puts on the page for this actor, read back out of the
     * rendered HTML: tile id => label, address and group. A request through the
     * panel's route, so Filament's own page authorization has already run.
     *
     * @return array<string, array{label: string, href: string, group: string}>
     */
    $this->renderedTiles = function (User $actor): array {
        $html = $this->actingAs($actor)->get(FinanceHub::getUrl())->assertSuccessful()->getContent();

        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument;
        $document->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $tiles = [];

        foreach ((new DOMXPath($document))->query('//a[@data-finance-hub-tile]') as $anchor) {
            $group = '';

            for ($node = $anchor->parentNode; $node instanceof DOMElement; $node = $node->parentNode) {
                if ($node->hasAttribute('data-finance-hub-group')) {
                    $group = $node->getAttribute('data-finance-hub-group');

                    break;
                }
            }

            $tiles[$anchor->getAttribute('data-finance-hub-tile')] = [
                'label' => trim((string) preg_replace('/\s+/', ' ', $anchor->textContent)),
                'href' => $anchor->getAttribute('href'),
                'group' => $group,
            ];
        }

        return $tiles;
    };
});

/*
|--------------------------------------------------------------------------
| The tile list
|--------------------------------------------------------------------------
*/

it('tiles exactly these fifteen destinations, in these groups, in this order', function () {
    $actual = [];

    foreach (FinanceHub::tiles() as $tile) {
        $actual[$tile['id']] = ['destination' => $tile['destination'], 'group' => $tile['group']];
    }

    $expected = [];

    foreach (FINANCE_HUB_TILES as $id => $tile) {
        $expected[$id] = ['destination' => $tile['destination'], 'group' => $tile['group']];
    }

    // Two tiles sharing an id would collapse in the map above; the count is what
    // notices, because the maps would then still be equal in size to one another.
    expect(FinanceHub::tiles())->toHaveCount(15)
        ->and($actual)->toBe($expected);
});

it('covers all nine permissions the tiles need, no more and no fewer', function () {
    expect(array_values(array_unique(array_column(FINANCE_HUB_TILES, 'permission'))))
        ->toBe(FINANCE_HUB_PERMISSIONS);
});

/*
|--------------------------------------------------------------------------
| Visibility: exactly the permission, and one fewer
|--------------------------------------------------------------------------
*/

it('shows a tile to an actor holding exactly its destination permission', function (string $id, array $tile) {
    $actor = ($this->actorWith)('access_admin_panel', $tile['permission']);

    $rendered = ($this->renderedTiles)($actor);

    // Exactly the tiles that need this permission, so a tile that ignored the
    // destination's rule and showed to everyone would be caught here too.
    expect(array_keys($rendered))->toEqualCanonicalizing(($this->tileIdsNeeding)($tile['permission']))
        ->and($rendered)->toHaveKey($id)
        ->and($rendered[$id]['label'])->toBe($tile['label'])
        ->and($rendered[$id]['group'])->toBe($tile['group']);
})->with('finance hub tiles');

it('hides a tile from an actor holding every other tile permission', function (string $id, array $tile) {
    $others = array_values(array_diff(FINANCE_HUB_PERMISSIONS, [$tile['permission']]));

    // The control: exactly one permission is missing, so a typo in the table could
    // not silently hand this actor all nine.
    expect($others)->toHaveCount(8);

    $actor = ($this->actorWith)('access_admin_panel', ...$others);

    $rendered = ($this->renderedTiles)($actor);

    $expectedIds = array_values(array_diff(array_keys(FINANCE_HUB_TILES), ($this->tileIdsNeeding)($tile['permission'])));

    expect($rendered)->not->toHaveKey($id)
        ->and(array_keys($rendered))->toEqualCanonicalizing($expectedIds);
})->with('finance hub tiles');

it('opens the hub for an actor holding only one tile permission', function (string $permission) {
    $actor = ($this->actorWith)('access_admin_panel', $permission);

    $this->actingAs($actor)
        ->get(FinanceHub::getUrl())
        ->assertSuccessful();

    expect(FinanceHub::canAccess())->toBeTrue();
})->with(FINANCE_HUB_PERMISSIONS);

/*
|--------------------------------------------------------------------------
| Nobody without a finance permission gets the hub
|--------------------------------------------------------------------------
*/

it('refuses the hub to an actor who can use the panel but holds no finance permission', function () {
    $actor = ($this->actorWith)('access_admin_panel');

    $this->actingAs($actor)
        ->get(FinanceHub::getUrl())
        ->assertForbidden();

    expect(FinanceHub::canAccess())->toBeFalse();
});

it('refuses the hub to an actor holding other, non-finance permissions', function () {
    $actor = ($this->actorWith)('access_admin_panel', 'view_any_student', 'view_any_batch', 'view_any_course');

    $this->actingAs($actor)
        ->get(FinanceHub::getUrl())
        ->assertForbidden();

    expect(FinanceHub::canAccess())->toBeFalse();
});

it('refuses the hub to a guest', function () {
    expect(FinanceHub::canAccess())->toBeFalse();

    $this->get(FinanceHub::getUrl())->assertRedirect();
});

/*
|--------------------------------------------------------------------------
| Layout
|--------------------------------------------------------------------------
*/

it('groups the tiles into money in, money out and reports', function () {
    $actor = ($this->actorWith)('access_admin_panel', ...FINANCE_HUB_PERMISSIONS);

    $rendered = ($this->renderedTiles)($actor);

    $idsIn = fn (string $group): array => array_keys(array_filter($rendered, fn (array $tile): bool => $tile['group'] === $group));

    expect($idsIn('money_in'))->toBe(['charges', 'payments', 'discounts', 'enroll_and_collect'])
        ->and($idsIn('money_out'))->toBe(['payroll_runs', 'staff_compensation', 'expenses', 'expense_categories'])
        ->and($idsIn('reports'))->toBe([
            'revenue_report',
            'outstanding_aged_report',
            'payment_method_report',
            'daily_tender_report',
            'wage_cost_report',
            'profit_report',
            'student_payment_history_report',
        ]);
});

it('does not draw a group heading for a group with no visible tile', function () {
    $actor = ($this->actorWith)('access_admin_panel', 'view_financial_report');

    $html = $this->actingAs($actor)->get(FinanceHub::getUrl())->assertSuccessful()->getContent();

    expect(substr_count($html, 'data-finance-hub-group="reports"'))->toBe(1)
        ->and($html)->not->toContain('data-finance-hub-group="money_in"')
        ->and($html)->not->toContain('data-finance-hub-group="money_out"');
});

/*
|--------------------------------------------------------------------------
| Every tile goes somewhere that exists
|--------------------------------------------------------------------------
*/

it('links each tile to an address that opens', function (string $id, array $tile) {
    $actor = ($this->actorWith)('access_admin_panel', ...FINANCE_HUB_PERMISSIONS);

    $rendered = ($this->renderedTiles)($actor);

    expect($rendered)->toHaveKey($id);

    $path = parse_url($rendered[$id]['href'], PHP_URL_PATH);

    // The address is the one written in the table, then it is requested for real:
    // a string that merely looks like a URL is not a route that exists.
    expect($path)->toBe($tile['path']);

    $this->actingAs($actor)->get($path)->assertSuccessful();
})->with('finance hub tiles');

/*
|--------------------------------------------------------------------------
| Wording
|--------------------------------------------------------------------------
|
| LocalizationTest's key scan only sees literal `__('group.key')` calls whose
| group is letters and underscores, so it can see neither this catalogue's
| hyphenated name nor keys built from a tile id. This is the check for them.
*/

it('has wording in the catalogue for the page, every group and every tile', function () {
    $keys = [
        'finance-hub.title',
        'finance-hub.navigation_label',
        'finance-hub.navigation_group',
    ];

    foreach (['money_in', 'money_out', 'reports'] as $group) {
        $keys[] = "finance-hub.groups.{$group}.heading";
        $keys[] = "finance-hub.groups.{$group}.description";
    }

    foreach (array_keys(FINANCE_HUB_TILES) as $id) {
        $keys[] = "finance-hub.tiles.{$id}.label";
        $keys[] = "finance-hub.tiles.{$id}.description";
    }

    $missing = [];

    foreach ($keys as $key) {
        $value = __($key);

        if (! is_string($value) || $value === $key || trim($value) === '') {
            $missing[] = $key;
        }
    }

    expect($keys)->toHaveCount(3 + 6 + 30)
        ->and($missing)->toBe([]);
});
