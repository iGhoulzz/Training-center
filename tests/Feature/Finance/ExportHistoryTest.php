<?php

declare(strict_types=1);

use App\Domain\Finance\Exports\DailyTenderReportExporter;
use App\Domain\Finance\Exports\OutstandingAgedReportExporter;
use App\Domain\Finance\Exports\PaymentMethodReportExporter;
use App\Domain\Finance\Exports\ProfitReportExporter;
use App\Domain\Finance\Exports\RevenueReportExporter;
use App\Domain\Finance\Exports\StudentPaymentHistoryExporter;
use App\Domain\Finance\Exports\WageCostReportExporter;
use App\Domain\Finance\Filament\Pages\ExportHistory;
use App\Domain\Finance\Filament\Pages\Reports\ProfitReportPage;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Actions\Action;
use Filament\Actions\Exports\Models\Export;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| The export history (phase 4, P4-T08)
|--------------------------------------------------------------------------
|
| A page that lists the viewer's own queued XLSX report exports and links each
| finished one to the download route that already exists. It LISTS AND LINKS; it
| never serves a file, so every refusal that matters is the route's, and this
| file proves that by asking the real route rather than the list.
|
| NO SUPER ADMIN ANYWHERE IN THIS FILE, ON PURPOSE. super_admin holds every
| permission, so it would open the page whichever ability the page checked and
| prove nothing about which. Every actor holds exactly the permissions named, and
| each access case is paired with an actor holding one fewer.
|
| EVERY EXPECTED VALUE IS WRITTEN OUT HERE BY HAND: the five status labels, the
| seven report titles, the centre-time rendering of a stored instant, the route's
| answer per row. Nothing is read back from the page's own status method, so a
| status the page got wrong cannot agree with itself. The one place the page and
| the route are compared with each other (the equivalence test) compares two
| OBSERVED maps, each against a map typed in this file.
|
| THE FIVE STATUSES, and the fixture that makes each:
|
|   queued       completed_at is null, requested at most an hour ago
|   overdue      completed_at is null, requested more than an hour ago
|   failed       completed, but the rows do not all succeed
|   ready        completed, rows complete, and the export directory is on its disk
|   unavailable  completed, rows complete, and the directory is gone (purged after
|                seven days) or the exporter is not a report exporter
*/
uses(RefreshDatabase::class);

/*
 * The three abilities that open the page: the panel, and the two financial-report
 * abilities ReportExportAuthorization asks for. Written out here rather than read
 * from that class, so a permission it stopped asking for fails this file.
 */
const EXPORT_HISTORY_PERMISSIONS = ['access_admin_panel', 'view_financial_report', 'export_financial_report'];

/*
 * The seven report exporters and the page title each one's report carries, typed
 * by hand from the report pages.
 */
dataset('export history reports', [
    'revenue' => [RevenueReportExporter::class, 'Revenue by course and batch'],
    'outstanding aged' => [OutstandingAgedReportExporter::class, 'Outstanding balances, aged'],
    'payment method' => [PaymentMethodReportExporter::class, 'Payment method breakdown'],
    'daily tender' => [DailyTenderReportExporter::class, 'Daily tender report'],
    'wage cost' => [WageCostReportExporter::class, 'Wage cost'],
    'profit' => [ProfitReportExporter::class, 'Profit'],
    'student payment history' => [StudentPaymentHistoryExporter::class, 'Student payment history'],
]);

/**
 * A fresh, validly signed link to the download route for this export.
 *
 * The route name is typed out rather than generated the way the page generates
 * it: this is the test asking the ROUTE about a row the list never linked.
 */
function exportHistorySignedUrl(Export $export): string
{
    return URL::temporarySignedRoute(
        'filament.admin.pages.reports.xlsx.download',
        now()->addDay(),
        ['export' => $export->getKey()],
    );
}

/**
 * The address of every entry in the signed-in actor's menu.
 *
 * @return list<string>
 */
function exportHistoryNavigationUrls(): array
{
    return collect(Filament::getNavigation())
        ->flatMap(fn ($group) => $group->getItems())
        ->map(fn ($item): string => $item->getUrl())
        ->values()
        ->all();
}

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    Storage::fake('local');
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    // An actor holding exactly the named permissions and nothing else.
    $this->actorWith = function (string ...$permissions): User {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(...$permissions);

        return $user->fresh();
    };

    $this->owner = fn (): User => ($this->actorWith)(...EXPORT_HISTORY_PERMISSIONS);

    /*
     * An Export row in the named state, owned by $owner, as Filament leaves one.
     * Built by hand rather than by running an export, so each state is exact; the
     * end-to-end test below is the one that runs a real export.
     *
     * 'ready' also puts a spreadsheet in the export directory on the faked disk.
     * 'unavailable' does not, which is what a purged directory looks like.
     */
    $this->makeExport = function (User $owner, string $state = 'ready', array $attributes = []): Export {
        $progress = match ($state) {
            'queued' => ['completed_at' => null, 'processed_rows' => 0, 'successful_rows' => 0],
            'failed' => ['completed_at' => now(), 'processed_rows' => 3, 'successful_rows' => 2],
            'ready', 'unavailable' => ['completed_at' => now(), 'processed_rows' => 3, 'successful_rows' => 3],
        };

        $export = new Export(array_merge([
            'exporter' => RevenueReportExporter::class,
            'file_disk' => 'local',
            'file_name' => 'report',
            'total_rows' => 3,
        ], $progress, $attributes));
        $export->user()->associate($owner);
        $export->save();

        if ($state === 'ready') {
            Storage::disk('local')->put(
                $export->getFileDirectory().'/report.xlsx',
                'xlsx-bytes-'.$export->getKey(),
            );
        }

        return $export->refresh();
    };

    // One export of every state, for the owner: the same page shows them all, so a
    // verdict that leaked from one row to another would show.
    $this->makeEveryState = function (User $owner): array {
        return [
            'queued' => ($this->makeExport)($owner, 'queued'),
            'failed' => ($this->makeExport)($owner, 'failed'),
            'ready' => ($this->makeExport)($owner, 'ready'),
            'unavailable' => ($this->makeExport)($owner, 'unavailable'),
        ];
    };

    /*
     * What the table's own "download" action says for a row, read out of the
     * component the way Filament's assertions read it. The check closure always
     * answers true: it is used as a getter, so a hidden action does not fail here.
     */
    $this->listMarksReady = function ($component, Export $export): bool {
        $visible = null;

        $component->assertTableActionExists('download', function (Action $action) use (&$visible): bool {
            $visible = $action->isVisible();

            return true;
        }, $export);

        return $visible === true;
    };

    $this->linkOf = function ($component, Export $export): string {
        $url = null;

        $component->assertTableActionExists('download', function (Action $action) use (&$url): bool {
            $url = $action->getUrl();

            return true;
        }, $export);

        return (string) $url;
    };
});

/*
|--------------------------------------------------------------------------
| Access: exactly the export boundary, and one fewer
|--------------------------------------------------------------------------
*/

it('opens for an actor holding exactly the panel and both report abilities', function (): void {
    $actor = ($this->owner)();

    // The control: nothing else is held, so no wider permission opened the page.
    expect($actor->getAllPermissions()->pluck('name')->sort()->values()->all())
        ->toBe(['access_admin_panel', 'export_financial_report', 'view_financial_report']);

    $this->actingAs($actor)->get(ExportHistory::getUrl())->assertSuccessful();

    expect(ExportHistory::canAccess())->toBeTrue();
});

it('refuses an actor missing one of the two report abilities', function (string $missing): void {
    $held = array_values(array_diff(EXPORT_HISTORY_PERMISSIONS, [$missing]));

    expect($held)->toHaveCount(2);

    $actor = ($this->actorWith)(...$held);

    $this->actingAs($actor)->get(ExportHistory::getUrl())->assertForbidden();

    expect(ExportHistory::canAccess())->toBeFalse();
})->with(['view_financial_report', 'export_financial_report']);

it('refuses an actor who can use the panel but holds neither report ability', function (): void {
    $actor = ($this->actorWith)('access_admin_panel');

    $this->actingAs($actor)->get(ExportHistory::getUrl())->assertForbidden();

    expect(ExportHistory::canAccess())->toBeFalse();
});

it('refuses an inactive user who holds every permission', function (): void {
    $actor = ($this->owner)();
    $actor->update(['is_active' => false]);

    // Was a bare 403. Since P4-T33 LogOutInactiveUser signs an inactive account
    // out and sends it to the login with the reason; the page is still never
    // reached.
    $this->actingAs($actor->fresh())->get(ExportHistory::getUrl())
        ->assertRedirect(Filament::getPanel('admin')->getLoginUrl(['reason' => 'deactivated']));
    $this->assertGuest('web');

    // The page's own rule, asked directly: the panel refuses an inactive account
    // before the page is reached, so the request above alone would not show it.
    expect(ExportHistory::canAccess())->toBeFalse();

    Livewire::actingAs($actor->fresh())->test(ExportHistory::class)->assertForbidden();
});

it('sends a guest to sign in', function (): void {
    expect(ExportHistory::canAccess())->toBeFalse();

    $this->get(ExportHistory::getUrl())->assertRedirect();
});

it('refuses a Livewire refresh once the export ability is revoked', function (): void {
    $actor = ($this->owner)();
    $this->actingAs($actor);

    $component = Livewire::test(ExportHistory::class)->assertSuccessful();

    $actor->revokePermissionTo('export_financial_report');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $component->call('$refresh')->assertForbidden();
});

/*
 * Two tests, not one: Filament builds the navigation once and keeps it, so a
 * second actor in the same test would be shown the first actor's menu.
 */
it('shows a navigation entry to the authorized actor', function (): void {
    $this->actingAs(($this->owner)());

    expect(exportHistoryNavigationUrls())->toContain(ExportHistory::getUrl());
});

it('shows no navigation entry to an actor with the export ability fewer', function (): void {
    $this->actingAs(($this->actorWith)('access_admin_panel', 'view_financial_report'));

    expect(exportHistoryNavigationUrls())->not->toContain(ExportHistory::getUrl());
});

it('sits in the financial reports group under its own label, with no sort of its own', function (): void {
    expect(ExportHistory::getNavigationGroup())->toBe('Financial reports')
        ->and(ExportHistory::getNavigationLabel())->toBe('Export history')
        ->and(ExportHistory::getNavigationSort())->toBeNull();
});

/*
|--------------------------------------------------------------------------
| Own rows only, newest first
|--------------------------------------------------------------------------
*/

it('lists the viewer\'s own exports and not another authorized user\'s', function (): void {
    $viewer = ($this->owner)();
    $other = ($this->owner)();

    $mine = [
        ($this->makeExport)($viewer, 'ready'),
        ($this->makeExport)($viewer, 'failed'),
        ($this->makeExport)($viewer, 'queued'),
    ];
    $theirs = [
        ($this->makeExport)($other, 'ready'),
        ($this->makeExport)($other, 'failed'),
    ];

    Livewire::actingAs($viewer)
        ->test(ExportHistory::class)
        ->assertCanSeeTableRecords($mine)
        ->assertCanNotSeeTableRecords($theirs)
        ->assertCountTableRecords(3);

    // And the other way round, so the filter is the owner and not "the first user".
    Livewire::actingAs($other)
        ->test(ExportHistory::class)
        ->assertCanSeeTableRecords($theirs)
        ->assertCanNotSeeTableRecords($mine)
        ->assertCountTableRecords(2);
});

it('shows nothing, and says so, to an authorized user with no exports', function (): void {
    ($this->makeExport)(($this->owner)(), 'ready');

    Livewire::actingAs(($this->owner)())
        ->test(ExportHistory::class)
        ->assertCountTableRecords(0)
        ->assertSee('You have not exported any spreadsheet reports yet.');
});

it('lists the newest request first, and the later row first when two share a second', function (): void {
    $viewer = ($this->owner)();

    $oldest = ($this->makeExport)($viewer, 'ready', ['created_at' => '2026-10-01 10:00:00']);
    $newest = ($this->makeExport)($viewer, 'ready', ['created_at' => '2026-10-05 10:00:00']);
    $tiedFirst = ($this->makeExport)($viewer, 'ready', ['created_at' => '2026-10-03 10:00:00']);
    $tiedSecond = ($this->makeExport)($viewer, 'ready', ['created_at' => '2026-10-03 10:00:00']);

    Livewire::actingAs($viewer)
        ->test(ExportHistory::class)
        ->assertCanSeeTableRecords([$newest, $tiedSecond, $tiedFirst, $oldest], inOrder: true);
});

/*
|--------------------------------------------------------------------------
| The statuses
|--------------------------------------------------------------------------
*/

it('labels each export by its status and offers the download on the ready one only', function (): void {
    $owner = ($this->owner)();
    $exports = ($this->makeEveryState)($owner);

    $component = Livewire::actingAs($owner)->test(ExportHistory::class);

    $labels = [
        'queued' => 'Queued',
        'failed' => 'Failed',
        'ready' => 'Ready',
        'unavailable' => 'No longer available',
    ];

    foreach ($labels as $state => $label) {
        $component->assertTableColumnFormattedStateSet('status', $label, $exports[$state]);
    }

    $component
        ->assertTableActionHidden('download', $exports['queued'])
        ->assertTableActionHidden('download', $exports['failed'])
        ->assertTableActionVisible('download', $exports['ready'])
        ->assertTableActionHidden('download', $exports['unavailable']);
});

it('offers a failed, overdue or otherwise unready export no link anywhere on the rendered page', function (): void {
    $owner = ($this->owner)();
    $exports = ($this->makeEveryState)($owner);
    $exports['overdue'] = ($this->makeExport)($owner, 'queued', ['created_at' => now()->subMinutes(61)]);

    $html = $this->actingAs($owner)->get(ExportHistory::getUrl())->assertSuccessful()->getContent();

    // The trailing "?" is the start of the signed query string, so id 1 cannot
    // match id 10.
    expect($html)->toContain('/admin/reports/xlsx/'.$exports['ready']->getKey().'?');

    foreach (['queued', 'overdue', 'failed', 'unavailable'] as $state) {
        expect($html)->not->toContain('/admin/reports/xlsx/'.$exports[$state]->getKey().'?');
    }
});

it('calls a completed export failed when its rows did not all succeed or were not all processed', function (): void {
    $owner = ($this->owner)();

    $unsuccessful = ($this->makeExport)($owner, 'failed');
    $unprocessed = ($this->makeExport)($owner, 'ready', ['processed_rows' => 2, 'successful_rows' => 3]);

    Livewire::actingAs($owner)
        ->test(ExportHistory::class)
        ->assertTableColumnFormattedStateSet('status', 'Failed', $unsuccessful)
        ->assertTableColumnFormattedStateSet('status', 'Failed', $unprocessed)
        ->assertTableActionHidden('download', $unsuccessful)
        ->assertTableActionHidden('download', $unprocessed);
});

/*
 * An export whose preparation failed never reaches ExportCompletion, so its
 * completed_at stays null for good and nothing is ever sent. The page cannot tell
 * that from a paused or backed-up queue, so it claims no cause: past an hour an
 * unfinished row reads OVERDUE, never Failed (Failed is a completed export with
 * rows missing, above). The cut-off is STRICTLY more than an hour: exactly sixty
 * minutes is still queued.
 *
 * Time is frozen to the second, so "sixty minutes ago" is sixty minutes to the
 * second when the page renders and not a fraction of a second either side of it.
 */
it('reads an unfinished export as queued up to an hour old and overdue beyond it, with no link either way', function (int $minutesOld, string $label): void {
    $this->freezeSecond();

    $owner = ($this->owner)();
    $export = ($this->makeExport)($owner, 'queued', ['created_at' => now()->subMinutes($minutesOld)]);

    Livewire::actingAs($owner)
        ->test(ExportHistory::class)
        ->assertTableColumnFormattedStateSet('status', $label, $export)
        ->assertTableActionHidden('download', $export);
})->with([
    'just requested' => [0, 'Queued'],
    '59 minutes old' => [59, 'Queued'],
    'exactly 60 minutes old' => [60, 'Queued'],
    '61 minutes old' => [61, 'Overdue'],
    'two days old' => [2 * 24 * 60, 'Overdue'],
]);

it('colours each status by its meaning, overdue as a warning', function (): void {
    $this->freezeSecond();

    $owner = ($this->owner)();
    $exports = ($this->makeEveryState)($owner);
    $exports['overdue'] = ($this->makeExport)($owner, 'queued', ['created_at' => now()->subMinutes(61)]);

    $component = Livewire::actingAs($owner)->test(ExportHistory::class);
    $column = $component->instance()->getTable()->getColumn('status');

    $colours = [];

    foreach ($exports as $name => $export) {
        $column->record($export);
        $column->clearCachedState();
        $colours[$name] = $column->getColor($column->getState());
    }

    expect($colours)->toBe([
        'queued' => 'info',
        'failed' => 'danger',
        'ready' => 'success',
        'unavailable' => 'gray',
        'overdue' => 'warning',
    ]);
});

it('calls a completed export of something other than a report unavailable, with no link', function (): void {
    $owner = ($this->owner)();

    // Complete rows AND files on disk, so the only thing wrong is the exporter.
    $foreign = ($this->makeExport)($owner, 'ready', ['exporter' => 'App\\Domain\\Elsewhere\\RetiredExporter']);

    Storage::disk('local')->assertExists($foreign->getFileDirectory().'/report.xlsx');

    Livewire::actingAs($owner)
        ->test(ExportHistory::class)
        ->assertTableColumnFormattedStateSet('status', 'No longer available', $foreign)
        ->assertTableColumnFormattedStateSet('report', 'Other export', $foreign)
        ->assertTableActionHidden('download', $foreign);
});

it('turns a ready export unavailable once its files are purged', function (): void {
    $owner = ($this->owner)();
    $export = ($this->makeExport)($owner, 'ready');

    $component = Livewire::actingAs($owner)
        ->test(ExportHistory::class)
        ->assertTableColumnFormattedStateSet('status', 'Ready', $export)
        ->assertTableActionVisible('download', $export);

    // What the nightly purge does seven days on: the directory goes, the row stays.
    Storage::disk('local')->deleteDirectory($export->getFileDirectory());
    Storage::disk('local')->assertMissing($export->getFileDirectory().'/report.xlsx');

    $component->call('$refresh')
        ->assertCanSeeTableRecords([$export])
        ->assertTableColumnFormattedStateSet('status', 'No longer available', $export)
        ->assertTableActionHidden('download', $export);
});

it('looks for a ready row\'s files once per render, however many parts of the table ask', function (): void {
    $owner = ($this->owner)();
    $exports = [($this->makeExport)($owner, 'ready'), ($this->makeExport)($owner, 'ready')];

    // The status column, its colour and the download action's visibility all need a
    // row's status, and "ready" costs a disk lookup. Count the lookups on the
    // export directories, passing each through to the real disk.
    $real = Storage::disk('local');
    $lookups = [];
    $spy = Mockery::mock($real)->makePartial();
    $spy->shouldReceive('exists')->andReturnUsing(function (string $path) use ($real, &$lookups): bool {
        $lookups[] = $path;

        return $real->exists($path);
    });
    Storage::set('local', $spy);

    Livewire::actingAs($owner)->test(ExportHistory::class)->assertCanSeeTableRecords($exports);

    sort($lookups);
    $directories = array_map(fn (Export $export): string => $export->getFileDirectory(), $exports);
    sort($directories);

    // One lookup per row: two ready rows, two lookups, each directory once.
    expect($lookups)->toBe($directories);
});

/*
|--------------------------------------------------------------------------
| Columns
|--------------------------------------------------------------------------
*/

it('titles a row by the report it exported', function (string $exporter, string $title): void {
    $owner = ($this->owner)();
    $export = ($this->makeExport)($owner, 'ready', ['exporter' => $exporter]);

    Livewire::actingAs($owner)
        ->test(ExportHistory::class)
        ->assertTableColumnFormattedStateSet('report', $title, $export);
})->with('export history reports');

it('shows the number of rows exported', function (): void {
    $owner = ($this->owner)();
    $export = ($this->makeExport)($owner, 'ready', ['total_rows' => 7, 'processed_rows' => 7, 'successful_rows' => 7]);

    Livewire::actingAs($owner)
        ->test(ExportHistory::class)
        ->assertTableColumnStateSet('total_rows', 7, $export);
});

it('shows the request time in the centre\'s timezone, not the stored one', function (): void {
    $owner = ($this->owner)();

    // 22:30 on the 10th in the stored zone (UTC) is 00:30 on the 11th in Tripoli,
    // two hours ahead: the date differs, so a column left in UTC fails.
    $export = ($this->makeExport)($owner, 'ready', ['created_at' => '2026-10-10 22:30:00']);

    Livewire::actingAs($owner)
        ->test(ExportHistory::class)
        ->assertTableColumnFormattedStateSet('created_at', 'Oct 11, 2026 00:30:00', $export)
        ->assertTableColumnFormattedStateNotSet('created_at', 'Oct 10, 2026 22:30:00', $export);
});

it('says under the title that it lists spreadsheets and that PDFs arrive as notifications', function (): void {
    Livewire::actingAs(($this->owner)())
        ->test(ExportHistory::class)
        ->assertSee('This page lists your spreadsheet report exports; PDF reports arrive as notifications and are not listed.');
});

/*
|--------------------------------------------------------------------------
| The link: signed, in a new tab, and the page does not serve
|--------------------------------------------------------------------------
*/

it('links a ready row to the signed download route in a new tab, with no handler of its own', function (): void {
    $owner = ($this->owner)();
    $export = ($this->makeExport)($owner, 'ready');

    $component = Livewire::actingAs($owner)->test(ExportHistory::class);

    $url = ($this->linkOf)($component, $export);
    $parts = parse_url($url);
    parse_str($parts['query'] ?? '', $query);

    expect($parts['path'])->toBe('/admin/reports/xlsx/'.$export->getKey())
        ->and($query)->toHaveKeys(['expires', 'signature']);

    $component
        ->assertTableActionShouldOpenUrlInNewTab('download', $export)
        ->assertTableActionExists('download', fn (Action $action): bool => ! $action->hasAction(), $export);
});

it('hands out a link that expires, so an old copy of the page does not keep one', function (): void {
    $owner = ($this->owner)();
    $export = ($this->makeExport)($owner, 'ready');

    $component = Livewire::actingAs($owner)->test(ExportHistory::class);
    $url = ($this->linkOf)($component, $export);

    $this->actingAs($owner)->get($url)->assertSuccessful();

    // Two days on, the signature is out of date: the route refuses before it ever
    // looks at the permission.
    $this->travel(2)->days();
    $this->actingAs($owner)->get($url)->assertForbidden();
});

it('rejects the download link with its signature altered', function (): void {
    $owner = ($this->owner)();
    $export = ($this->makeExport)($owner, 'ready');

    $component = Livewire::actingAs($owner)->test(ExportHistory::class);
    $url = ($this->linkOf)($component, $export);

    $this->actingAs($owner)->get($url.'0')->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| The page agrees with the route, because the route decides
|--------------------------------------------------------------------------
*/

it('marks as ready exactly the exports the download route serves', function (): void {
    $owner = ($this->owner)();
    $exports = ($this->makeEveryState)($owner);
    // Three more ways to be refused that look serviceable on the surface: a failed
    // export whose partial files are still on disk, rows processed short of the
    // total while every row succeeded, and files that are there for an exporter the
    // route will not serve.
    $exports['failed with files'] = ($this->makeExport)($owner, 'ready', ['processed_rows' => 3, 'successful_rows' => 2]);
    $exports['processed short'] = ($this->makeExport)($owner, 'ready', ['processed_rows' => 2, 'successful_rows' => 3]);
    $exports['foreign exporter'] = ($this->makeExport)($owner, 'ready', ['exporter' => 'App\\Domain\\Elsewhere\\RetiredExporter']);
    // An export still running: every row done and the directory already on disk,
    // but not yet marked complete. The route refuses it, so the list must not link.
    $exports['in flight'] = ($this->makeExport)($owner, 'ready', ['completed_at' => null]);
    // Unfinished and requested inside the hour, and unfinished and requested beyond
    // it: queued and overdue on the list, and the route refuses both.
    $exports['unfinished 59 minutes'] = ($this->makeExport)($owner, 'queued', ['created_at' => now()->subMinutes(59)]);
    $exports['unfinished 61 minutes'] = ($this->makeExport)($owner, 'queued', ['created_at' => now()->subMinutes(61)]);

    $component = Livewire::actingAs($owner)->test(ExportHistory::class);

    $listed = [];
    $served = [];

    foreach ($exports as $name => $export) {
        $listed[$name] = ($this->listMarksReady)($component, $export);
        // A freshly signed link for the row, as its owner, whether or not the list
        // offered one.
        $served[$name] = $this->actingAs($owner)->get(exportHistorySignedUrl($export))->getStatusCode();
    }

    expect($listed)->toBe([
        'queued' => false,
        'failed' => false,
        'ready' => true,
        'unavailable' => false,
        'failed with files' => false,
        'processed short' => false,
        'foreign exporter' => false,
        'in flight' => false,
        'unfinished 59 minutes' => false,
        'unfinished 61 minutes' => false,
    ])->and($served)->toBe([
        'queued' => 404,
        'failed' => 404,
        'ready' => 200,
        'unavailable' => 404,
        'failed with files' => 404,
        'processed short' => 404,
        'foreign exporter' => 404,
        'in flight' => 404,
        'unfinished 59 minutes' => 404,
        'unfinished 61 minutes' => 404,
    ]);
});

it('has a ready row\'s link refused once the owner loses the export ability, which is the route\'s check and not the list\'s', function (): void {
    $owner = ($this->owner)();
    $export = ($this->makeExport)($owner, 'ready');

    $component = Livewire::actingAs($owner)->test(ExportHistory::class);
    $url = ($this->linkOf)($component, $export);

    // The control: the very link the table holds works while the ability is held.
    $this->actingAs($owner)->get($url)->assertSuccessful();

    $owner->revokePermissionTo('export_financial_report');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    // The link is still validly signed and still unexpired, and the owner still
    // holds the panel and view_financial_report. Only the route's own re-check can
    // refuse it.
    $this->actingAs($owner)->get($url)->assertForbidden();
});

it('refuses a ready row\'s link to a different authorized user', function (): void {
    $owner = ($this->owner)();
    $other = ($this->owner)();
    $export = ($this->makeExport)($owner, 'ready');

    $component = Livewire::actingAs($owner)->test(ExportHistory::class);
    $url = ($this->linkOf)($component, $export);

    $this->actingAs($other)->get($url)->assertForbidden();
    $this->actingAs($owner)->get($url)->assertSuccessful();
});

it('lists a real export as ready, and its link downloads the spreadsheet', function (): void {
    $actor = ($this->owner)();

    Livewire::actingAs($actor)
        ->test(ProfitReportPage::class)
        ->set('filters.from', '2026-10')
        ->set('filters.to', '2026-10')
        ->call('applyFilters')
        ->callAction('export_xlsx')
        ->assertHasNoActionErrors();

    $export = Export::query()->sole();

    $component = Livewire::actingAs($actor)
        ->test(ExportHistory::class)
        ->assertCanSeeTableRecords([$export])
        ->assertTableColumnFormattedStateSet('report', 'Profit', $export)
        ->assertTableColumnFormattedStateSet('status', 'Ready', $export)
        ->assertTableActionVisible('download', $export);

    $response = $this->actingAs($actor)->get(($this->linkOf)($component, $export));

    $response->assertSuccessful();

    // An XLSX file is a zip archive, and every zip starts with these two bytes.
    expect(substr($response->streamedContent(), 0, 2))->toBe('PK');
});

/*
|--------------------------------------------------------------------------
| Wording
|--------------------------------------------------------------------------
*/

it('has exactly the wording the page uses in the catalogue, every key resolving', function (): void {
    $keys = [
        'export_history.title',
        'export_history.navigation_label',
        'export_history.subheading',
        'export_history.empty',
        'export_history.other_export',
        'export_history.columns.report',
        'export_history.columns.requested_at',
        'export_history.columns.rows',
        'export_history.columns.status',
        'export_history.statuses.queued',
        'export_history.statuses.ready',
        'export_history.statuses.failed',
        'export_history.statuses.unavailable',
        'export_history.statuses.overdue',
        'export_history.actions.download',
    ];

    $missing = [];

    foreach ($keys as $key) {
        $value = __($key);

        if (! is_string($value) || $value === $key || trim($value) === '') {
            $missing[] = $key;
        }
    }

    // Both directions: a key the page needs and the catalogue lacks, and a key the
    // catalogue keeps that nothing uses.
    $catalogued = array_map(
        fn (string $key): string => 'export_history.'.$key,
        array_keys(Arr::dot((array) __('export_history'))),
    );

    expect($missing)->toBe([])
        ->and($catalogued)->toEqualCanonicalizing($keys);
});
