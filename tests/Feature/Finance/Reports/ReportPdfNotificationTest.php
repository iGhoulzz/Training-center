<?php

declare(strict_types=1);

use App\Domain\Finance\Exports\ReportDataset;
use App\Domain\Finance\Exports\ReportKind;
use App\Domain\Finance\Exports\ReportSnapshot;
use App\Domain\Finance\Jobs\GenerateReportPdfJob;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Filament\Livewire\DatabaseNotifications;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleComponents\Checksum;
use Livewire\Mechanisms\HandleRequests\HandleRequests;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| A queued report PDF reaches the person who asked for it (P4-T22, QA finding F2)
|--------------------------------------------------------------------------
|
| GenerateReportPdfJob delivers the file as a database notification, and the
| admin panel never switched database notifications on: no bell, so the signed
| download link could never be reached from the UI (spec §8: "the user is
| notified in-app when the file is ready"). The job was right and stays as it is;
| these prove the panel now shows what it sends.
|
| WHAT IS NOT REPEATED HERE. That a different admin, a staff member and a
| signed-out visitor are each refused on a report's signed download URL is
| proved by ReportExportTest ("queues the pdf action and the job stores a real
| pdf then notifies the requester"), and so is the missing-signature refusal.
| This file proves the notification gets to its owner, only its owner sees it,
| and turning the component on did not unseal the forced password change.
*/

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->system = app(SystemRoleWriter::class);

    // Each test runs inside RefreshDatabase's transaction, which rolls back at the end, so no
    // notification row survives from one test to the next.
    Storage::fake('private');

    $this->adminAccount = function (bool $mustChangePassword = false): User {
        $user = User::factory()->create([
            'is_active' => true,
            'must_change_password' => $mustChangePassword,
            // Known, because ForcePasswordChange is the guard under test below.
            'password' => Hash::make('existing-password-1'),
        ]);
        $this->system->assignRoles($user, 'admin');

        return $user->refresh();
    };

    /**
     * Run the real job for $requester and return it, so its reference is known.
     *
     * The title is a sentinel nothing else in the application prints, and the
     * dataset has one row, so the job renders a genuine PDF rather than failing early.
     */
    $this->runPdfJobFor = function (User $requester): GenerateReportPdfJob {
        $snapshot = new ReportSnapshot(
            kind: ReportKind::Revenue,
            title: 'SENTINEL-T22-REPORT-TITLE',
            locale: 'en',
            filters: ['Period' => '2026-08'],
            dataset: new ReportDataset(
                ['amount' => 'Amount'],
                [['carrier_id' => 1, 'cells' => ['amount' => '125.000']]],
            ),
        );

        $job = new GenerateReportPdfJob($snapshot->toArray(), $requester->getKey());
        $job->handle();

        return $job;
    };

    /**
     * Every root Livewire component the URL renders, as the browser would see it,
     * with the raw snapshot string a checksum can be replayed against.
     *
     * @return array<int, array{name: mixed, snapshot: string}>
     */
    $this->rootComponents = function (string $url): array {
        $html = (string) $this->get($url)->assertSuccessful()->getContent();

        preg_match_all('/wire:snapshot="([^"]*)"/', $html, $matches);

        return array_map(function (string $raw): array {
            $snapshot = html_entity_decode($raw, ENT_QUOTES);

            return [
                'name' => json_decode($snapshot, true)['memo']['name'] ?? null,
                'snapshot' => $snapshot,
            ];
        }, $matches[1]);
    };

    /**
     * The payload an open tab posts back to Livewire for its bell.
     *
     * clearNotifications is the call, deliberately: a refusal that did not stop
     * the delete is the failure that matters, so the rows are asserted afterwards.
     * The control below posts the identical payload from a user in good standing
     * and proves the call does delete them, which is what makes "still there"
     * mean something.
     *
     * @return array<string, mixed>
     */
    $this->bellPayload = function (string $url, ?string $claimedPath = null): array {
        $snapshot = collect(($this->rootComponents)($url))
            ->firstWhere('name', DatabaseNotifications::class)['snapshot'] ?? null;

        expect($snapshot)->not->toBeNull("No rendered snapshot for the notifications bell on {$url}.");

        if ($claimedPath !== null) {
            // Re-sealed with the app key, which an attacker does not hold. This is not a
            // reachable attack; it makes the snapshot claim the one path the guard exempts
            // so that what is being proved is the guard reading the COMPONENT.
            $decoded = json_decode($snapshot, true);
            unset($decoded['checksum']);
            $decoded['memo']['path'] = $claimedPath;
            $decoded['checksum'] = Checksum::generate($decoded);
            $snapshot = (string) json_encode($decoded);
        }

        return ['components' => [[
            'snapshot' => $snapshot,
            'updates' => [],
            'calls' => [['path' => '', 'method' => 'clearNotifications', 'params' => []]],
        ]]];
    };

    // The URI is resolved, not written: this install serves Livewire from an obfuscated prefix.
    // The X-Livewire header is what makes the endpoint accept the post at all. See
    // ForcePasswordChangeTest for both, and for why each test posts ONCE.
    $this->interact = fn (array $payload) => $this
        ->withHeaders(['X-Livewire' => 'true'])
        ->postJson(app(HandleRequests::class)->getUpdateUri(), $payload);
});

/*
|--------------------------------------------------------------------------
| The admin panel shows database notifications, and only the admin panel does
|--------------------------------------------------------------------------
*/

it('enables database notifications on the admin panel', function () {
    expect(Filament::getPanel('admin')->hasDatabaseNotifications())->toBeTrue();
});

/*
 * NO POLLING, BECAUSE OF THE IDLE TIMEOUT (spec §4: "8-hour idle timeout").
 *
 * The timeout is SESSION_LIFETIME on the database driver, and each Livewire poll is a request
 * that refreshes the session's last_activity. Filament's default 30s poll would keep a tab left
 * open on a front-desk PC signed in for ever, so the panel switches it off. The bell then
 * refreshes on each page load. The interval is asserted on the panel AND on what the loaded
 * component renders, because the attribute is what the browser obeys.
 */
it('has no polling interval on the admin panel, so an open tab cannot keep a session alive past the idle timeout', function () {
    expect(Filament::getPanel('admin')->getDatabaseNotificationsPollingInterval())->toBeNull();
});

it('renders the loaded bell without a wire:poll attribute', function () {
    $admin = ($this->adminAccount)();
    Notification::make()->title('SENTINEL-T22-NO-POLL')->sendToDatabase($admin);

    $bell = Livewire::actingAs($admin)->test(DatabaseNotifications::class);

    // Loaded and holding a notification, so the modal that carries the poll attribute is rendered:
    // the sentinel proves it is there, and the attribute is what the browser obeys.
    $bell->assertSee('SENTINEL-T22-NO-POLL');
    expect($bell->html())->not->toContain('wire:poll');
});

it('leaves the student portal without database notifications', function () {
    expect(Filament::getPanel('student')->hasDatabaseNotifications())->toBeFalse();
});

it('renders the notifications bell on an admin page for a user in good standing', function () {
    $this->actingAs(($this->adminAccount)());

    $names = array_column(($this->rootComponents)('/admin'), 'name');

    expect($names)->toContain(DatabaseNotifications::class);
});

/*
|--------------------------------------------------------------------------
| The notification reaches the person who asked for the report
|--------------------------------------------------------------------------
*/

it('shows the requester the pdf-ready notification with a download action that serves the pdf', function () {
    $this->freezeTime();
    $requester = ($this->adminAccount)();
    $job = ($this->runPdfJobFor)($requester);

    $bell = Livewire::actingAs($requester)->test(DatabaseNotifications::class);

    $bell->assertSee('Your PDF export is ready.')
        ->assertSee('Download PDF');

    $notification = $bell->instance()->getNotifications()->sole();
    $action = $bell->instance()->getNotification($notification)->getActions()[0];

    // A signed, one-day link on the report PDF route, built by hand from the route's name and
    // parameters rather than read back from the job.
    $expected = URL::temporarySignedRoute('filament.admin.pages.reports.pdf.download', now()->addDay(), [
        'requester' => $requester->getKey(),
        'reference' => $job->reference,
    ]);

    expect($action->getUrl())->toBe($expected)
        ->and($expected)->toContain("/admin/reports/pdf/{$requester->getKey()}/{$job->reference}?expires=")
        ->and($expected)->toContain('&signature=');

    // And the link the bell offers is the one that delivers the file.
    $response = $this->actingAs($requester)->get($action->getUrl());

    $response->assertSuccessful()->assertHeader('content-type', 'application/pdf');
    expect($response->streamedContent())->toStartWith('%PDF-');
});

it('counts the pdf-ready notification as one unread on the requesters bell', function () {
    $requester = ($this->adminAccount)();
    ($this->runPdfJobFor)($requester);

    $bell = Livewire::actingAs($requester)->test(DatabaseNotifications::class);

    expect($bell->instance()->getUnreadNotificationsCount())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| A notification is visible only to the user it was sent to
|--------------------------------------------------------------------------
*/

it('shows another admin none of the requesters notifications, only their own', function () {
    $requester = ($this->adminAccount)();
    $other = ($this->adminAccount)();
    $job = ($this->runPdfJobFor)($requester);

    // The other admin has a notification of their own, so an empty bell cannot be mistaken for a
    // bell that failed to render at all.
    Notification::make()->title('SENTINEL-T22-OTHER-ADMIN-ONLY')->sendToDatabase($other);

    $theirs = Livewire::actingAs($other)->test(DatabaseNotifications::class);

    $theirs->assertSee('SENTINEL-T22-OTHER-ADMIN-ONLY')
        ->assertDontSee('Your PDF export is ready.')
        ->assertDontSee($job->reference);

    expect($theirs->instance()->getNotifications())->toHaveCount(1)
        ->and($theirs->instance()->getUnreadNotificationsCount())->toBe(1);

    $mine = Livewire::actingAs($requester)->test(DatabaseNotifications::class);

    $mine->assertSee($job->reference)
        ->assertDontSee('SENTINEL-T22-OTHER-ADMIN-ONLY');
});

it('does not let another admin clear the requesters notifications', function () {
    $requester = ($this->adminAccount)();
    $other = ($this->adminAccount)();
    ($this->runPdfJobFor)($requester);

    Livewire::actingAs($other)->test(DatabaseNotifications::class)->call('clearNotifications');

    expect($requester->notifications()->count())->toBe(1);
});

/*
|--------------------------------------------------------------------------
| The forced password change stays sealed now that the component exists
|--------------------------------------------------------------------------
|
| PasswordChange renders the simple layout with hasTopbar => false, and says that
| the database notifications component sits behind that flag and behind
| filament()->hasDatabaseNotifications(), which was off. It is on now, so both
| halves are proved: the component is not rendered where the account is held, and
| a request to it from an account that must change its password is refused by the
| same guard that refuses every other component.
*/

it('renders no notifications bell on the forced password change page', function () {
    $this->actingAs(($this->adminAccount)(true));

    $names = array_column(($this->rootComponents)('/admin/password-change'), 'name');

    expect($names)->not->toContain(DatabaseNotifications::class);
});

it('lets the bell of an account in good standing be driven', function () {
    $user = ($this->adminAccount)();
    $this->actingAs($user);
    Notification::make()->title('SENTINEL-T22-CONTROL')->sendToDatabase($user);

    $payload = ($this->bellPayload)('/admin');

    // The control for the two refusals below, on the identical payload: a redirect there could
    // otherwise be a broken post rather than the guard. It also shows the call does delete.
    ($this->interact)($payload)->assertSuccessful();

    expect($user->notifications()->count())->toBe(0);
});

it('refuses the bell of a tab opened before the account was flagged, and the delete does not happen', function () {
    $user = ($this->adminAccount)();
    $this->actingAs($user);
    Notification::make()->title('SENTINEL-T22-STALE-TAB')->sendToDatabase($user);

    $payload = ($this->bellPayload)('/admin');

    // ONLY the rotation flag, as LivewirePersistentGuardTest does: changing the password hash too
    // would let AuthenticateSession refuse the post first and this would pass without the guard.
    $user->forceFill(['must_change_password' => true])->save();

    ($this->interact)($payload)->assertRedirect('/admin/password-change');

    expect($user->notifications()->count())->toBe(1);
});

it('lets a bell claiming the password change page through while the account is in good standing', function () {
    $user = ($this->adminAccount)();
    $this->actingAs($user);
    Notification::make()->title('SENTINEL-T22-CLAIMED-CONTROL')->sendToDatabase($user);

    // The control for the refusal below: the same re-sealed snapshot, an account nobody holds.
    $payload = ($this->bellPayload)('/admin', claimedPath: 'admin/password-change');

    ($this->interact)($payload)->assertSuccessful();

    expect($user->notifications()->count())->toBe(0);
});

it('refuses the bell even when its snapshot claims the password change page', function () {
    $user = ($this->adminAccount)();
    $this->actingAs($user);
    Notification::make()->title('SENTINEL-T22-CLAIMED-PATH')->sendToDatabase($user);

    // The page the guard exempts. The path alone must not carry the bell through it.
    $payload = ($this->bellPayload)('/admin', claimedPath: 'admin/password-change');

    $user->forceFill(['must_change_password' => true])->save();

    ($this->interact)($payload)->assertRedirect('/admin/password-change');

    expect($user->notifications()->count())->toBe(1);
});
