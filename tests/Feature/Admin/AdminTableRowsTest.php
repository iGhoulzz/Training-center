<?php

declare(strict_types=1);

use App\Domain\Enrollment\Models\Course;
use App\Domain\Enrollment\Models\Student;
use App\Domain\Finance\Filament\Resources\ChargeResource\Pages\ListCharges;
use App\Domain\Finance\Filament\Resources\ExpenseResource\Pages\ListExpenses;
use App\Domain\Finance\Filament\Resources\PaymentResource\Pages\ListPayments;
use App\Domain\Finance\Models\Charge;
use App\Domain\Finance\Models\Discount;
use App\Domain\Finance\Models\Expense;
use App\Domain\Finance\Models\Payment;
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

it('summarises a deleted record as fields removed, not changed', function () {
    $course = Course::factory()->create();
    $course->delete();
    $entry = Activity::query()->where('event', 'deleted')->where('subject_type', Course::class)->latest('id')->firstOrFail();
    // A deletion carries only the values the record had: nothing on the new side.
    expect($entry->attribute_changes->get('attributes') ?? [])->toBe([]);
    $count = count($entry->attribute_changes->get('old'));
    $actor = ($this->actor)(['view_any_activity', 'view_activity']);

    expect($count)->toBeGreaterThan(1);

    Livewire::actingAs($actor)->test(ListActivities::class)
        ->assertCanSeeTableRecords([$entry])
        ->assertTableColumnStateSet('attribute_changes', $count.' fields removed', $entry);
});

/*
|--------------------------------------------------------------------------
| A column hidden by default is only ever hidden because its facts are on the row elsewhere
|--------------------------------------------------------------------------
*/

it('keeps a bill\'s enrolment, course, discount and write-off reason on its row while their own columns are hidden', function () {
    $charge = Charge::factory()->create(['list_price' => '200.000', 'amount' => '180.000', 'discount_id' => Discount::factory()->create()->getKey(), 'discount_percentage' => '10.00']);
    $charge->forceFill(['written_off_at' => now(), 'written_off_by' => User::factory()->create()->getKey(), 'written_off_reason' => 'SENTINEL-WRITE-OFF-REASON'])->save();
    $actor = ($this->actor)(['view_any_charge']);

    $page = Livewire::actingAs($actor)->test(ListCharges::class)->assertCanSeeTableRecords([$charge]);
    $table = $page->instance()->getTable();

    // The columns that carry a fact already shown elsewhere are toggleable and start hidden...
    foreach (['enrollment.reference', 'enrollment.batch.course.code', 'discount_percentage', 'written_off'] as $name) {
        expect($table->getColumn($name)->isToggleable())->toBeTrue($name)
            ->and($table->getColumn($name)->isToggledHiddenByDefault())->toBeTrue($name);
    }

    // ...because the row says each of them in words: the enrolment under the bill, the course under
    // the batch, the discount under the amount, and the reason on the badge's tooltip.
    $text = strip_tags($page->html());
    expect($text)->toContain($charge->enrollment->reference)
        ->toContain($charge->enrollment->batch->course->code)
        ->toContain('Discount 10.00%')
        ->and($table->getColumn('payment_state')->record($charge)->getTooltip())->toBe('SENTINEL-WRITE-OFF-REASON');
});

it('keeps a receipt\'s reversal reason, recorder and pending PDF on its row while their own columns are hidden', function () {
    $payment = Payment::factory()->reversed()->create(['recorded_by' => ($recorder = User::factory()->create(['name' => 'Sentinel Recorder']))->getKey(), 'receipt_path' => null]);
    $payment->forceFill(['reversal_reason' => 'SENTINEL-REVERSAL-REASON'])->save();
    $actor = ($this->actor)(['view_any_payment', 'view_payment']);

    $page = Livewire::actingAs($actor)->test(ListPayments::class)->assertCanSeeTableRecords([$payment]);
    $table = $page->instance()->getTable();

    foreach (['recordedBy.name', 'reversed', 'receipt_pending'] as $name) {
        expect($table->getColumn($name)->isToggleable())->toBeTrue($name)
            ->and($table->getColumn($name)->isToggledHiddenByDefault())->toBeTrue($name);
    }

    expect(strip_tags($page->html()))->toContain('Recorded by Sentinel Recorder')->toContain(__('payments.receipt_generating'))
        ->and($table->getColumn('payment_state')->record($payment)->getTooltip())->toBe('SENTINEL-REVERSAL-REASON');
});

it('lets a user hide or show every secondary column, and starts none hidden that carries a fact found nowhere else', function () {
    $actor = ($this->actor)(['view_any_activity', 'view_activity']);
    $table = Livewire::actingAs($actor)->test(ListActivities::class)->instance()->getTable();

    foreach (['attribute_changes', 'properties.ip'] as $name) {
        expect($table->getColumn($name)->isToggleable())->toBeTrue($name)
            ->and($table->getColumn($name)->isToggledHiddenByDefault())->toBeFalse($name);
    }
});
