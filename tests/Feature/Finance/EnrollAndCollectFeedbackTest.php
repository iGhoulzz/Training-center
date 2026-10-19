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
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| Enrol & Collect — what the operator is told and shown (P4-T32)
|--------------------------------------------------------------------------
|
| QA findings P3-1 (a full batch warns), P3-3 (after Confirm the form is
| emptied, the stage moves on, and a second Confirm does nothing while the
| payment panel is open), P3-6 (wording of the amount errors) and P3-11 (long
| step text stays inside its own step card).
|
| P4-T29 replaced the Wizard with server-owned stages (`flowStep`), so the P3-3
| and P3-11 cases assert the stage and the step cards rather than Filament's
| wizard event and header classes. What each case protects is unchanged.
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

it('empties the form after Confirm and moves a collector to the Collect stage, not an emptied review', function () {
    $student = Student::factory()->create();
    $batch = Batch::factory()->active()->create();

    ($this->confirmed)($this->collector, $student, $batch)
        ->assertSet('flowStep', 4)
        ->assertSet('collectionChargeId', Charge::query()->sole()->getKey())
        ->assertSet('data.student_id', null)
        ->assertSet('data.batch_id', null)
        ->assertSee(__('collect.take_payment'))
        ->assertDontSee(__('collect.review_enrolment'));
});

it('keeps the stage on the server, so the browser cannot be left on a stale step', function () {
    // The Wizard kept its step in Alpine, which a server fill could not reset.
    // The stage is now a locked server property the browser cannot write.
    Livewire::actingAs($this->collector)->test(EnrollAndCollect::class)
        ->set('flowStep', 4);
})->throws(CannotUpdateLockedPropertyException::class);

it('returns a walk-in desk actor without payment rights to step 1 as well, with no panel to wait on', function () {
    ($this->confirmed)($this->enroller, Student::factory()->create(), Batch::factory()->active()->create())
        ->assertSet('flowStep', 1)
        ->assertSet('data.student_id', null)
        ->assertSet('collectionChargeId', null);
});

it('does not return to step 1 when Confirm is refused, so the operator can correct what they entered', function () {
    $student = Student::factory()->create();
    $batch = Batch::factory()->active()->create();
    Enrollment::factory()->for($batch)->for($student)->create();

    Livewire::actingAs($this->enroller)->test(EnrollAndCollect::class)
        ->set('data.student_id', $student->getKey())->call('advance')
        ->set('data.batch_id', $batch->getKey())->call('advance')
        ->assertSet('flowStep', 4)
        ->call('confirm')
        ->assertSet('flowStep', 4)
        ->assertSet('data.student_id', $student->getKey())
        ->assertSet('data.batch_id', $batch->getKey());

    expect(Enrollment::query()->count())->toBe(1);
});

it('does nothing on a second Confirm while the payment panel is open: no second enrolment or bill, no required-field errors', function () {
    $student = Student::factory()->create();
    $batch = Batch::factory()->active()->create();

    $page = ($this->confirmed)($this->collector, $student, $batch);
    $chargeId = Charge::query()->sole()->getKey();
    $page->assertSet('collectionChargeId', $chargeId);

    // The emptied form, then a second Confirm straight after.
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

it('offers no Confirm while the payment panel is open, and offers it again once the panel is closed', function () {
    $batch = Batch::factory()->active()->create();
    $toReview = fn ($page, Student $student) => $page
        ->set('data.student_id', $student->getKey())->call('advance')
        ->set('data.batch_id', $batch->getKey())->call('advance');
    // The opening tag of the Confirm button. `wire:loading.attr="disabled"` is
    // on an enabled button too, so "disabled" is looked for as an attribute of
    // its own, not as a substring.
    $confirmTag = fn (string $html): string => preg_match('/<button\b[^>]*wire:click="confirm"[^>]*>/s', $html, $match) === 1
        ? $match[0]
        : '';
    $isDisabled = fn (string $tag): bool => preg_match('/\sdisabled(?:=|\s|>)/', $tag) === 1;

    $page = $toReview(Livewire::actingAs($this->collector)->test(EnrollAndCollect::class), Student::factory()->create());
    $enabledTag = $confirmTag($page->html());
    expect($enabledTag)->not->toBe('')
        ->and($isDisabled($enabledTag))->toBeFalse();

    $page->call('confirm');

    expect($confirmTag($page->html()))->toBe('');

    $toReview($page->call('finishCollection'), Student::factory()->create());

    $reopenedTag = $confirmTag($page->html());
    expect($reopenedTag)->not->toBe('')
        ->and($isDisabled($reopenedTag))->toBeFalse();
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
| A test cannot see a layout. It can see that each step card's value sits in a
| shrinkable box (`min-w-0`) and is clipped to one line (`truncate`), so a long
| student name cannot push its card wider than its share of the row. The lead
| checks the result in a browser at 375, 768 and 1440 px.
*/

it('bounds each step card so a long chosen value stays inside its own step', function () {
    $student = Student::factory()->create(['first_name' => str_repeat('Longname', 8), 'last_name' => 'Walkin']);
    $html = Livewire::actingAs(($this->actor)(['create_enrollment', 'view_student']))
        ->withQueryParams(['student' => $student->getKey()])
        ->test(EnrollAndCollect::class)->html();
    $document = new DOMDocument;
    $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
    $values = (new DOMXPath($document))->query('//nav[@aria-label="'.__('collect.enrolment_steps').'"]//button/span[contains(concat(" ", @class, " "), " min-w-0 ")]/span[contains(concat(" ", @class, " "), " truncate ")]');

    expect($values->length)->toBeGreaterThan(0);
    $texts = [];
    foreach ($values as $value) {
        $texts[] = $value->textContent;
    }
    expect(implode(' ', $texts))->toContain($student->first_name);
});
