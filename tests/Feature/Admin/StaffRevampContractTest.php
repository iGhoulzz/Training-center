<?php

declare(strict_types=1);

use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Domain\Staff\Filament\Resources\ActivityResource;
use App\Domain\Staff\Filament\Resources\ActivityResource\Pages\ListActivities;
use App\Domain\Staff\Filament\Resources\ActivityResource\Pages\ViewActivity;
use App\Domain\Staff\Filament\Resources\UserResource;
use App\Domain\Staff\Filament\Resources\UserResource\Pages\EditUser;
use App\Domain\Staff\Filament\Resources\UserResource\Pages\ListUsers;
use App\Domain\Staff\Models\StaffProfile;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actor = function (array $permissions): User {
        $actor = User::factory()->create(['is_active' => true]);
        $actor->givePermissionTo(['access_admin_panel', ...$permissions]);
        expect($actor->roles()->exists())->toBeFalse();

        return $actor;
    };
});

it('combines an account and its permitted profile in a responsive row', function () {
    $profile = StaffProfile::factory()->create(['job_title' => 'Visible employment canary']);
    $actor = ($this->actor)(['view_any_user', 'view_staff_profile', 'view_any_activity']);
    $page = Livewire::actingAs($actor)->test(ListUsers::class)->assertCanSeeTableRecords([$profile->user]);
    $html = preg_replace('/wire:snapshot="[^"]*"/', '', $page->html());
    expect($html)->toContain('Visible employment canary', 'fi-ta-grid', 'md:grid-cols-[minmax(140px,1.6fr)_minmax(104px,1.1fr)_minmax(96px,1fr)_minmax(168px,1.1fr)]');
    $page->assertTableColumnVisible('activity_link');

    $less = ($this->actor)(['view_any_user', 'view_any_activity']);
    $page = Livewire::actingAs($less)->test(ListUsers::class);
    expect($page->html())->not->toContain('Visible employment canary');
});

it('gates the activity cross-link by its own permission', function () {
    $target = User::factory()->create();
    Livewire::actingAs(($this->actor)(['view_any_user', 'view_any_activity']))->test(ListUsers::class)
        ->assertTableColumnVisible('activity_link')
        ->assertTableColumnStateSet('activity_link', __('staff.activity_link'), $target);
    Livewire::actingAs(($this->actor)(['view_any_user']))->test(ListUsers::class)
        ->assertTableColumnHidden('activity_link');
});

it('gates payroll independently and links activity to the selected account', function () {
    $target = User::factory()->create();
    $page = Livewire::actingAs(($this->actor)(['view_any_user', 'view_any_activity', 'view_any_staff_compensation']))
        ->test(ListUsers::class)->assertTableColumnVisible('payroll_link');
    $url = $page->instance()->getTable()->getColumn('activity_link')->record($target)->getUrl();
    parse_str(parse_url($url, PHP_URL_QUERY), $query);
    expect($query['tableFilters']['causer_id']['value'])->toBe((string) $target->id);
    Livewire::actingAs(($this->actor)(['view_any_user', 'view_any_activity']))->test(ListUsers::class)
        ->assertTableColumnHidden('payroll_link');
});

it('permits ordinary account changes with the exact grants and refuses one fewer', function (string $operation) {
    $target = User::factory()->create(['is_active' => true]);
    app(SystemRoleWriter::class)->assignRoles($target, 'staff');
    $grant = $operation === 'delete' ? 'delete_user' : ($operation === 'demote' ? 'assign_role' : 'update_user');
    $base = $operation === 'delete' ? ['view_any_user'] : ['view_any_user', 'update_user'];
    $actor = ($this->actor)(array_values(array_unique([...$base, $grant])));

    if ($operation === 'delete') {
        Livewire::actingAs($actor)->test(ListUsers::class)->callTableAction('delete', $target);
        expect($target->fresh()->trashed())->toBeTrue();
    } else {
        Livewire::actingAs($actor)->test(EditUser::class, ['record' => $target->id])
            ->fillForm($operation === 'demote' ? ['roles' => ['admin']] : ['is_active' => false])->call('save')->assertHasNoFormErrors();
        expect($operation === 'demote' ? $target->fresh()->roles()->pluck('name')->all() : $target->fresh()->is_active)
            ->toBe($operation === 'demote' ? ['admin'] : false);
    }

    $other = User::factory()->create(['is_active' => true]);
    app(SystemRoleWriter::class)->assignRoles($other, 'staff');
    $less = ($this->actor)(array_values(array_diff($base, [$grant])));
    if ($operation === 'delete') {
        Livewire::actingAs($less)->test(ListUsers::class)->assertTableActionHidden('delete', $other);
    } elseif ($operation === 'demote') {
        Livewire::actingAs($less)->test(EditUser::class, ['record' => $other->id])
            ->fillForm(['roles' => ['admin']])->call('save')->assertHasFormErrors(['roles.0']);
    } else {
        $this->actingAs($less)->get(UserResource::getUrl('edit', ['record' => $other]))->assertForbidden();
    }
    expect($other->fresh()->is_active)->toBeTrue()->and($other->fresh()->trashed())->toBeFalse()
        ->and($other->fresh()->roles()->pluck('name')->all())->toBe(['staff']);
})->with(['deactivate', 'demote', 'delete']);

it('keeps the last active super admin protected from exact-permission actors', function () {
    $last = User::factory()->create(['is_active' => true]);
    app(SystemRoleWriter::class)->assignRoles($last, 'super_admin');
    $actor = ($this->actor)(['view_any_user', 'update_user', 'delete_user', 'assign_role']);
    $this->actingAs($actor)->get(UserResource::getUrl('edit', ['record' => $last]))->assertForbidden();
    Livewire::actingAs($actor)->test(ListUsers::class)->assertTableActionHidden('delete', $last);
    expect($last->fresh()->is_active)->toBeTrue()->and($last->fresh()->isSuperAdmin())->toBeTrue()
        ->and($last->fresh()->trashed())->toBeFalse();
});

it('renders an append-only timeline with no write actions even for a super admin', function (string $actorKind) {
    $actor = ($this->actor)(['view_any_activity', 'view_activity']);
    if ($actorKind === 'super_admin') {
        app(SystemRoleWriter::class)->assignRoles($actor, 'super_admin');
    }
    activity()->event('created')->withProperties(['email' => 'timeline@example.test'])->log('created');
    $entry = Activity::query()->latest('id')->firstOrFail();
    $page = Livewire::actingAs($actor)->test(ListActivities::class)->assertCanSeeTableRecords([$entry]);
    expect($page->html())->toContain('fi-ta-stack');
    expect(array_keys($page->instance()->getTable()->getActions()))->toBe([])
        ->and(array_keys($page->instance()->getTable()->getBulkActions()))->toBe([]);
    Livewire::actingAs($actor)->test(ViewActivity::class, ['record' => $entry->id])
        ->assertActionDoesNotExist('edit')->assertActionDoesNotExist('delete');
    expect(array_keys(ActivityResource::getPages()))->toBe(['index', 'view']);
})->with(['exact', 'super_admin']);

it('refuses the activity view when its exact actor has one permission fewer', function () {
    activity()->event('created')->log('created');
    $entry = Activity::query()->latest('id')->firstOrFail();
    $actor = ($this->actor)(['view_any_activity']);
    $this->actingAs($actor)->get(ActivityResource::getUrl('view', ['record' => $entry]))->assertForbidden();
});
