<?php

declare(strict_types=1);

use App\Domain\Enrollment\Models\Student;
use App\Domain\Finance\Filament\Resources\ExpenseResource\Pages\ListExpenses;
use App\Domain\Finance\Models\Expense;
use App\Domain\Staff\Filament\Resources\ActivityResource;
use App\Domain\Staff\Filament\Resources\ActivityResource\Pages\ListActivities;
use App\Domain\Staff\Filament\Resources\ActivityResource\Pages\ViewActivity;
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

/*
|--------------------------------------------------------------------------
| The activity log's one-line summary
|--------------------------------------------------------------------------
*/

it('shows a sign-in with no summary and no code font', function () {
    activity()->event('logged_in')->log('logged_in');
    $entry = Activity::query()->latest('id')->firstOrFail();
    $actor = ($this->actor)(['view_any_activity', 'view_activity']);

    $page = Livewire::actingAs($actor)->test(ListActivities::class)->assertCanSeeTableRecords([$entry]);

    // Nothing to summarise, so nothing is drawn: not the old "no field changes" line, not a dash.
    $page->assertTableColumnStateSet('attribute_changes', null, $entry)
        ->assertSee(__('activity.system'));
    expect($page->html())->not->toContain(__('activity.no_changes'))->not->toContain('font-mono');
});

it('summarises an update as the number of fields that changed', function () {
    $student = Student::factory()->create(['first_name' => 'Before', 'last_name' => 'Surname']);
    $student->update(['first_name' => 'After', 'last_name' => 'Renamed']);
    $entry = Activity::query()->where('event', 'updated')->latest('id')->firstOrFail();
    $actor = ($this->actor)(['view_any_activity', 'view_activity']);

    // Two fields were edited, counted by hand: first_name and last_name.
    Livewire::actingAs($actor)->test(ListActivities::class)
        ->assertCanSeeTableRecords([$entry])
        ->assertTableColumnStateSet('attribute_changes', '2 fields changed', $entry);

    $student->update(['first_name' => 'Third']);
    $single = Activity::query()->where('event', 'updated')->latest('id')->firstOrFail();

    Livewire::actingAs($actor)->test(ListActivities::class)
        ->assertTableColumnStateSet('attribute_changes', '1 field changed', $single);
});

it('summarises a new record as fields recorded, not changed', function () {
    Student::factory()->create();
    $entry = Activity::query()->where('event', 'created')->latest('id')->firstOrFail();
    $count = count($entry->attribute_changes->get('attributes'));

    expect(ActivityResource::summarise($entry))->toBe($count.' fields recorded')->not->toContain('changed');
});

it('carries an explicit event\'s substance in the summary, not a count', function () {
    activity()->event('roles_changed')->withProperties(['added' => ['sentinel-role'], 'removed' => []])->log('roles_changed');
    $entry = Activity::query()->latest('id')->firstOrFail();

    expect(ActivityResource::summarise($entry))->toContain('sentinel-role')->not->toContain('fields');
});

it('keeps the full field-by-field changes on the entry\'s own page', function () {
    $student = Student::factory()->create(['first_name' => 'Before', 'last_name' => 'Surname']);
    $student->update(['first_name' => 'After']);
    $entry = Activity::query()->where('event', 'updated')->latest('id')->firstOrFail();
    $actor = ($this->actor)(['view_any_activity', 'view_activity']);

    $list = Livewire::actingAs($actor)->test(ListActivities::class)->assertCanSeeTableRecords([$entry]);
    // The list names only how many; the values are on the page the row opens.
    expect(strip_tags($list->html()))->not->toContain('Before → After');

    Livewire::actingAs($actor)->test(ViewActivity::class, ['record' => $entry->getKey()])
        ->assertSee('Before → After');
});

/*
|--------------------------------------------------------------------------
| The expense list's labelled status
|--------------------------------------------------------------------------
*/

it('labels a reversed expense and offers it no Reverse, and labels a live one and offers it', function () {
    $live = Expense::factory()->create();
    $reversed = Expense::factory()->reversed()->create();
    $reverser = ($this->actor)(['view_any_expense', 'view_expense', 'reverse_expense']);

    Livewire::actingAs($reverser)->test(ListExpenses::class)
        ->assertCanSeeTableRecords([$live, $reversed])
        ->assertTableColumnFormattedStateSet('reversed', 'Recorded', $live)
        ->assertTableColumnFormattedStateSet('reversed', 'Reversed', $reversed)
        ->assertTableActionVisible('reverse', $live)
        ->assertTableActionHidden('reverse', $reversed);
});

it('does not offer Reverse to an actor one permission short', function () {
    $live = Expense::factory()->create();
    $reader = ($this->actor)(['view_any_expense', 'view_expense']);

    Livewire::actingAs($reader)->test(ListExpenses::class)
        ->assertTableColumnFormattedStateSet('reversed', 'Recorded', $live)
        ->assertTableActionHidden('reverse', $live);
});

it('headlines the status column "Status", where an unlabelled icon stood', function () {
    Expense::factory()->create();
    $reader = ($this->actor)(['view_any_expense', 'view_expense']);

    $column = Livewire::actingAs($reader)->test(ListExpenses::class)->instance()->getTable()->getColumn('reversed');

    expect($column->getLabel())->toBe('Status')->and($column->isBadge())->toBeTrue();
});
