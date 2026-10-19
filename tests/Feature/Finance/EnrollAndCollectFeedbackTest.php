<?php

declare(strict_types=1);

use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\Student;
use App\Domain\Finance\Filament\Pages\EnrollAndCollect;
use App\Domain\Finance\Models\Charge;
use App\Domain\Finance\Models\Payment;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Enrol & Collect — what the operator is told and shown (P4-T32)
|--------------------------------------------------------------------------
|
| QA findings P3-1 (a full batch warns), P3-3 (after Confirm the wizard is
| back on step 1 and a second Confirm does nothing while the payment panel is
| open), P3-6 (wording of the amount errors) and P3-11 (the wizard header's
| long step text stays inside its own step).
|
| EVERY ACTOR HOLDS EXACTLY THE PERMISSIONS NAMED, NEVER A ROLE.
*/
uses(RefreshDatabase::class);

/**
 * Every notification sent so far, as title and status.
 *
 * Reading them takes them out of the session, so this is called once per test.
 *
 * @return list<array{title: string, status: string}>
 */
function enrollFeedbackSentNotifications(): array
{
    $component = new Notifications;
    $component->mount();

    return $component->notifications
        ->map(fn (FilamentNotification $notification): array => [
            'title' => (string) $notification->getTitle(),
            'status' => (string) $notification->getStatus(),
        ])
        ->values()
        ->all();
}

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Queue::fake();

    $this->actor = function (array $permissions): User {
        $actor = User::factory()->create(['is_active' => true]);
        $actor->givePermissionTo(['access_admin_panel', ...$permissions]);
        expect($actor->roles()->exists())->toBeFalse();

        return $actor;
    };

    $this->enroller = ($this->actor)(['create_enrollment']);
    $this->collector = ($this->actor)(['create_enrollment', 'create_payment']);

    $this->confirmed = fn (User $actor, Student $student, Batch $batch) => Livewire::actingAs($actor)
        ->test(EnrollAndCollect::class)
        ->fillForm(['student_id' => $student->getKey(), 'batch_id' => $batch->getKey()])
        ->call('confirm');
});

/*
|--------------------------------------------------------------------------
| P3-1 — a batch that is now over capacity warns, as the batch screen does
|--------------------------------------------------------------------------
*/

it('warns that the batch is over capacity when this enrolment takes it past its ceiling', function () {
    $batch = Batch::factory()->active()->create(['capacity' => 1]);
    Enrollment::factory()->for($batch)->create();

    ($this->confirmed)($this->enroller, Student::factory()->create(), $batch);

    expect(enrollFeedbackSentNotifications())->toBe([
        ['title' => __('collect.confirmed'), 'status' => 'success'],
        ['title' => __('enrollment.over_capacity_warning'), 'status' => 'warning'],
    ]);
});

it('does not warn when the batch still has room, is exactly full, or states no ceiling', function (int $capacity, int $alreadyEnrolled) {
    $batch = Batch::factory()->active()->create(['capacity' => $capacity]);
    Enrollment::factory()->count($alreadyEnrolled)->for($batch)->create();

    ($this->confirmed)($this->enroller, Student::factory()->create(), $batch);

    expect(Enrollment::query()->where('batch_id', $batch->getKey())->count())->toBe($alreadyEnrolled + 1)
        ->and(enrollFeedbackSentNotifications())->toBe([
            ['title' => __('collect.confirmed'), 'status' => 'success'],
        ]);
})->with([
    'room to spare' => [5, 0],
    'exactly full after this one' => [2, 1],
    'no stated ceiling, however many' => [0, 3],
]);

/*
|--------------------------------------------------------------------------
| P3-3 — after Confirm: back to step 1, and no second Confirm under an open panel
|--------------------------------------------------------------------------
*/

it('returns the wizard to its first step with an empty form after Confirm', function () {
    $student = Student::factory()->create();
    $batch = Batch::factory()->active()->create();

    ($this->confirmed)($this->collector, $student, $batch)
        ->assertDispatched('go-to-wizard-step', key: 'form.enroll-and-bill-wizard', step: 'form.student-step')
        ->assertSet('data.student_id', null)
        ->assertSet('data.batch_id', null);
});

it('names, in that event, the Wizard and the step the page actually renders', function () {
    // The event is compared in the browser against the keys the Wizard rendered
    // with, so a dispatch naming anything else moves nothing. Both ids are the
    // components' own absolute keys, written out by hand here.
    $html = Livewire::actingAs($this->collector)->test(EnrollAndCollect::class)->html();

    expect($html)
        ->toContain('id="form.enroll-and-bill-wizard"')
        ->toContain('id="form.student-step"');
});

it('returns a walk-in desk actor without payment rights to step 1 as well, with no panel to wait on', function () {
    ($this->confirmed)($this->enroller, Student::factory()->create(), Batch::factory()->active()->create())
        ->assertDispatched('go-to-wizard-step', key: 'form.enroll-and-bill-wizard', step: 'form.student-step')
        ->assertSet('collectionChargeId', null);
});

it('does not return to step 1 when Confirm is refused, so the operator can correct what they entered', function () {
    $student = Student::factory()->create();
    $batch = Batch::factory()->active()->create();
    Enrollment::factory()->for($batch)->for($student)->create();

    ($this->confirmed)($this->enroller, $student, $batch)
        ->assertNotDispatched('go-to-wizard-step')
        ->assertSet('data.student_id', $student->getKey());

    expect(Enrollment::query()->count())->toBe(1);
});

it('does nothing on a second Confirm while the payment panel is open: no second enrolment or bill, no required-field errors', function () {
    $student = Student::factory()->create();
    $batch = Batch::factory()->active()->create();

    $page = ($this->confirmed)($this->collector, $student, $batch);
    $chargeId = Charge::query()->sole()->getKey();
    $page->assertSet('collectionChargeId', $chargeId);

    // The emptied wizard, then a second Confirm straight after.
    $page->call('confirm')
        ->assertHasNoErrors()
        ->assertSet('collectionChargeId', $chargeId);

    // And again with the form refilled for someone else: still nothing.
    $other = Student::factory()->create();
    $page->fillForm(['student_id' => $other->getKey(), 'batch_id' => $batch->getKey()])
        ->call('confirm')
        ->assertHasNoErrors()
        ->assertSet('collectionChargeId', $chargeId);

    expect(Enrollment::query()->count())->toBe(1)
        ->and(Charge::query()->count())->toBe(1)
        ->and(enrollFeedbackSentNotifications())->toBe([
            ['title' => __('collect.confirmed'), 'status' => 'success'],
        ]);
});

it('renders Confirm disabled while the payment panel is open, and enabled again once it is closed', function () {
    $page = Livewire::actingAs($this->collector)->test(EnrollAndCollect::class);
    // The opening tag of the Confirm button. `wire:loading.attr="aria-disabled"`
    // is on an enabled button too, so "disabled" is looked for as an attribute
    // of its own, not as a substring.
    $confirmTag = fn (string $html): string => preg_match('/<button\b[^>]*>(?=(?:(?!<\/button>).)*?Confirm)/s', $html, $match) === 1
        ? $match[0]
        : '';
    $isDisabled = fn (string $tag): bool => preg_match('/\sdisabled(?:=|\s|>)/', $tag) === 1;

    $enabledTag = $confirmTag($page->html());
    expect($enabledTag)->toContain('wire:click="confirm"')
        ->and($isDisabled($enabledTag))->toBeFalse();

    $page->fillForm([
        'student_id' => Student::factory()->create()->getKey(),
        'batch_id' => Batch::factory()->active()->create()->getKey(),
    ])->call('confirm');

    expect($isDisabled($confirmTag($page->html())))->toBeTrue();

    $page->call('finishCollection');

    expect($isDisabled($confirmTag($page->html())))->toBeFalse();
});

it('lets the operator enrol the next walk-in once the panel is closed with Done', function () {
    $batch = Batch::factory()->active()->create();

    $page = ($this->confirmed)($this->collector, Student::factory()->create(), $batch)
        ->call('finishCollection')
        ->assertSet('collectionChargeId', null);

    $next = Student::factory()->create();
    $page->fillForm(['student_id' => $next->getKey(), 'batch_id' => $batch->getKey()])
        ->call('confirm');

    $nextCharge = Charge::query()->whereHas('enrollment', fn ($query) => $query->where('student_id', $next->getKey()))->sole();

    expect(Enrollment::query()->count())->toBe(2)
        ->and(Charge::query()->count())->toBe(2);

    $page->assertSet('collectionChargeId', $nextCharge->getKey());
});

/*
|--------------------------------------------------------------------------
| P3-6 — wording
|--------------------------------------------------------------------------
|
| The expected sentences are written out by hand, not read back from the keys
| under test: a message that was hardcoded in the page, or one key edited
| without the others, would otherwise agree with itself.
*/

it('tells a negative amount to enter more than zero, not to mind its decimal places', function () {
    $page = ($this->confirmed)($this->collector, Student::factory()->create(), Batch::factory()->active()->create());

    $page->fillForm([
        'amount' => '-5',
        'tenders' => [['method' => 'cash', 'amount' => '5.000', 'external_reference' => null]],
    ], 'collectForm')->call('finalize');

    expect($page->instance()->getErrorBag()->get('collect.amount'))->toBe(['Enter an amount greater than zero.'])
        ->and(Payment::query()->count())->toBe(0);
});

it('tells a negative tender amount to enter more than zero as well', function () {
    $page = ($this->confirmed)($this->collector, Student::factory()->create(), Batch::factory()->active()->create());

    $page->fillForm([
        'amount' => '5.000',
        'tenders' => [['method' => 'cash', 'amount' => '-5', 'external_reference' => null]],
    ], 'collectForm')->call('finalize');

    expect($page->instance()->getErrorBag()->get('collect.tenders.0.amount'))->toBe(['Enter an amount greater than zero.'])
        ->and(Payment::query()->count())->toBe(0);
});

it('still tells zero to enter more than zero, and a fourth decimal place to mind its decimals', function (string $typed, string $message) {
    $page = ($this->confirmed)($this->collector, Student::factory()->create(), Batch::factory()->active()->create());

    $page->fillForm([
        'amount' => $typed,
        'tenders' => [['method' => 'cash', 'amount' => '5.000', 'external_reference' => null]],
    ], 'collectForm')->call('finalize');

    expect($page->instance()->getErrorBag()->first('collect.amount'))->toBe($message)
        ->and(Payment::query()->count())->toBe(0);
})->with([
    'zero' => ['0.000', 'Enter an amount greater than zero.'],
    'minus zero' => ['-0.000', 'Enter an amount greater than zero.'],
    'a fourth decimal place' => ['1.2345', 'Enter an amount with at most three decimal places, for example 125.500.'],
    'text' => ['abc', 'Enter an amount with at most three decimal places, for example 125.500.'],
]);

it('words the three "at most three decimal places" messages the same', function () {
    $sentence = 'Enter an amount with at most three decimal places, for example 125.500.';

    expect(__('collect.amount_format_error'))->toBe($sentence)
        ->and(__('charges.amount_format_error'))->toBe($sentence)
        ->and(__('expenses.amount_invalid'))->toBe($sentence);
});

/*
|--------------------------------------------------------------------------
| P3-11 — long step text stays inside its own step
|--------------------------------------------------------------------------
|
| A test cannot see a layout. It can see that the page's own classes no longer
| name flex rules on a grid (`flex-wrap`, `flex-1` do nothing on Filament's
| wizard header) and do bound the step text. The lead checks the result in a
| browser at 375, 768 and 1440 px.
*/

it('bounds the wizard header so a long step description wraps inside its own step', function () {
    $html = Livewire::actingAs($this->enroller)->test(EnrollAndCollect::class)->html();

    expect($html)
        ->toContain('[&_.fi-sc-wizard-header]:grid-cols-[repeat(auto-fit,minmax(10rem,1fr))]')
        ->toContain('md:[&_.fi-sc-wizard-header]:grid-flow-row')
        ->toContain('[&_.fi-sc-wizard-header-step-text]:min-w-0')
        ->toContain('[&_.fi-sc-wizard-header-step-description]:line-clamp-2')
        ->not->toContain('[&_.fi-sc-wizard-header]:flex-wrap')
        ->not->toContain('[&_.fi-sc-wizard-header-step]:min-w-32.5');
});
