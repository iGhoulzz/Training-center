<?php

declare(strict_types=1);

use App\Domain\Enrollment\Actions\AssignInstructorAction;
use App\Domain\Enrollment\Actions\RemoveInstructorAction;
use App\Domain\Enrollment\Actions\WithdrawEnrollmentAction;
use App\Domain\Enrollment\Enums\BatchStatus;
use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Enrollment\Exceptions\BatchClosedException;
use App\Domain\Enrollment\Exceptions\EnrollmentNotWithdrawableException;
use App\Domain\Enrollment\Filament\Resources\BatchResource\Pages\ViewBatch;
use App\Domain\Enrollment\Filament\Resources\BatchResource\RelationManagers\EnrollmentsRelationManager;
use App\Domain\Enrollment\Filament\Resources\BatchResource\RelationManagers\InstructorsRelationManager;
use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Course;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\Student;
use App\Domain\Finance\Actions\EnrollAndBillAction;
use App\Domain\Finance\Filament\Resources\DiscountResource\Pages\ListDiscounts;
use App\Domain\Finance\Models\Charge;
use App\Domain\Finance\Models\Discount;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Models\PaymentAllocation;
use App\Domain\Finance\Services\StudentBalanceQuery;
use App\Domain\Staff\Models\StaffProfile;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Notifications\Livewire\Notifications;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * The batch screens say what happened, and offer only what can work (P4-T31).
 *
 * Seven writes used to succeed in silence, two buttons used to be offered on
 * rows where they could only be refused, and the Enrolments tab labelled the
 * student's whole balance as the enrolment's own.
 */
uses(RefreshDatabase::class);

/**
 * Every notification sent so far, as title, status and whether it stays.
 *
 * Reading them takes them out of the session, so this is called once per
 * assertion. Filament's assertNotified() is not used for the "no other toast"
 * checks: it runs after an earlier assert has already emptied the session and
 * then passes whatever was sent.
 *
 * @return list<array{title: string, status: string, persistent: bool}>
 */
function batchScreenSentNotifications(): array
{
    $component = new Notifications;
    $component->mount();

    return $component->notifications
        ->map(fn (FilamentNotification $notification): array => [
            'title' => (string) $notification->getTitle(),
            'status' => (string) $notification->getStatus(),
            'persistent' => $notification->getDuration() === 'persistent',
        ])
        ->values()
        ->all();
}

/** One success toast, as batchScreenSentNotifications() reports it. */
function batchScreenSuccess(string $title): array
{
    return ['title' => $title, 'status' => 'success', 'persistent' => false];
}

/** One refusal toast, as batchScreenSentNotifications() reports it. */
function batchScreenRefusal(string $title): array
{
    return ['title' => $title, 'status' => 'danger', 'persistent' => true];
}

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    // Exactly the named permissions. No role is assigned, so no actor here is
    // a super admin who would pass whatever ability the code asked.
    $this->actorWith = function (string ...$permissions): User {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(...$permissions);

        return $user->fresh();
    };

    $this->course = Course::factory()->create(['total_hours' => 30]);
    $this->batch = Batch::factory()->for($this->course)->active()->create(['capacity' => 10, 'total_hours' => null]);

    $this->mountEnrollments = fn (User $actor, ?Batch $batch = null) => Livewire::actingAs($actor)
        ->test(EnrollmentsRelationManager::class, [
            'ownerRecord' => $batch ?? $this->batch,
            'pageClass' => ViewBatch::class,
        ]);

    $this->mountInstructors = fn (User $actor, ?Batch $batch = null) => Livewire::actingAs($actor)
        ->test(InstructorsRelationManager::class, [
            'ownerRecord' => $batch ?? $this->batch,
            'pageClass' => ViewBatch::class,
        ]);

    /*
     * A request that skips the button: mount the named table action, fill its
     * form with VALID data, and call it. MOUNTING IS NOT THE ATTACK, CALLING IS.
     * mountAction() returns null identically for an unknown name, a disabled
     * action and an unauthorized one, so each use is paired with an authorized
     * call that did something.
     *
     * The data matters: without it an action with a required field fails form
     * validation, and a refusal that is really a validation error would pass
     * for the wrong reason when the authorization it stands for is removed.
     *
     * @param  array<string, mixed>  $data
     */
    $this->crafted = function ($component, string $name, ?Model $record = null, array $data = []) {
        $component->call('mountAction', $name, [], ['table' => true, ...($record === null ? [] : ['recordKey' => (string) $record->getKey()])]);

        if ($component->get('mountedActions') !== []) {
            foreach ($data as $key => $value) {
                $component->set("mountedActions.0.data.{$key}", $value);
            }
        }

        return $component->call('callMountedAction');
    };

    $this->instructor = function (string $name): User {
        $user = User::factory()->create(['is_active' => true, 'name' => $name]);
        StaffProfile::factory()->for($user)->instructor()->create();

        return $user->refresh();
    };

    /** A bill for one enrolment, at the stated list price. */
    $this->bill = fn (Enrollment $enrollment, string $price): Charge => Charge::factory()->create([
        'enrollment_id' => $enrollment->getKey(),
        'list_price' => $price,
    ]);

    /** A payment of $amount against one bill, optionally since reversed. */
    $this->pay = function (Charge $charge, Student $student, string $amount, bool $reversed = false): void {
        $payment = Payment::factory()->create(['student_id' => $student->getKey()]);

        if ($reversed) {
            $payment->forceFill([
                'reversed_at' => now(),
                'reversed_by' => User::factory()->create()->getKey(),
                'reversal_reason' => 'Paid in error',
            ])->saveQuietly();
        }

        PaymentAllocation::factory()->create([
            'payment_id' => $payment->getKey(),
            'charge_id' => $charge->getKey(),
            'amount' => $amount,
        ]);
    };

    /**
     * Stand in for a final Action that refuses, for the refusals the screen can
     * no longer reach through a button.
     */
    $this->refusingAction = function (string $class, Throwable $refusal): void {
        app()->instance($class, new class($refusal)
        {
            public function __construct(private readonly Throwable $refusal) {}

            public function execute(): never
            {
                throw $this->refusal;
            }
        });
    };
});

/*
|--------------------------------------------------------------------------
| P3-4: every write says it worked
|--------------------------------------------------------------------------
*/

it('tells the reader an instructor was assigned, and an actor one grant short gets no write and no toast', function () {
    $sara = ($this->instructor)('Sara');
    $actor = ($this->actorWith)('view_batch', 'assign_instructor');

    ($this->mountInstructors)($actor)
        ->callTableAction('assign', null, ['user_id' => $sara->getKey(), 'assigned_hours' => 18])
        ->assertHasNoTableActionErrors();

    expect(batchScreenSentNotifications())->toBe([batchScreenSuccess('Instructor assigned.')])
        ->and((int) DB::table('batch_instructor')->value('assigned_hours'))->toBe(18);

    $short = ($this->actorWith)('view_batch');
    $other = Batch::factory()->for($this->course)->active()->create();

    ($this->crafted)(($this->mountInstructors)($short, $other), 'assign', null, [
        'user_id' => $sara->getKey(),
        'assigned_hours' => 18,
    ]);

    expect(batchScreenSentNotifications())->toBe([])
        ->and(DB::table('batch_instructor')->where('batch_id', $other->getKey())->count())->toBe(0);
});

it('tells the reader the assigned hours were updated, and an actor one grant short gets no write and no toast', function () {
    $sara = ($this->instructor)('Sara');
    $this->batch->instructors()->attach([$sara->getKey() => ['assigned_hours' => 18]]);

    ($this->mountInstructors)(($this->actorWith)('view_batch', 'assign_instructor'))
        ->callTableAction('edit_hours', $sara, ['assigned_hours' => 24])
        ->assertHasNoTableActionErrors();

    expect(batchScreenSentNotifications())->toBe([batchScreenSuccess('Assigned hours updated.')])
        ->and((int) DB::table('batch_instructor')->value('assigned_hours'))->toBe(24);

    ($this->crafted)(($this->mountInstructors)(($this->actorWith)('view_batch')), 'edit_hours', $sara, ['assigned_hours' => 99]);

    expect(batchScreenSentNotifications())->toBe([])
        ->and((int) DB::table('batch_instructor')->value('assigned_hours'))->toBe(24);
});

it('tells the reader an instructor was removed, and an actor one grant short gets no write and no toast', function () {
    $sara = ($this->instructor)('Sara');
    $this->batch->instructors()->attach([$sara->getKey() => ['assigned_hours' => 18]]);

    ($this->crafted)(($this->mountInstructors)(($this->actorWith)('view_batch')), 'remove', $sara);

    expect(batchScreenSentNotifications())->toBe([])
        ->and(DB::table('batch_instructor')->count())->toBe(1);

    ($this->mountInstructors)(($this->actorWith)('view_batch', 'assign_instructor'))
        ->callTableAction('remove', $sara);

    expect(batchScreenSentNotifications())->toBe([batchScreenSuccess('Instructor removed.')])
        ->and(DB::table('batch_instructor')->count())->toBe(0);
});

it('tells the reader a discount was deactivated, and an actor one grant short cannot', function () {
    $discount = Discount::factory()->create();

    $short = ($this->actorWith)('view_any_discount');
    Livewire::actingAs($short)->test(ListDiscounts::class)->assertTableActionHidden('deactivate', $discount);
    ($this->crafted)(Livewire::actingAs($short)->test(ListDiscounts::class), 'deactivate', $discount);

    expect(batchScreenSentNotifications())->toBe([])
        ->and($discount->fresh()?->is_active)->toBeTrue();

    Livewire::actingAs(($this->actorWith)('view_any_discount', 'manage_pricing'))
        ->test(ListDiscounts::class)
        ->callTableAction('deactivate', $discount);

    expect(batchScreenSentNotifications())->toBe([batchScreenSuccess('Discount deactivated.')])
        ->and($discount->fresh()?->is_active)->toBeFalse();
});

it('tells the reader a student was enrolled, and an actor one grant short gets no write and no toast', function () {
    $student = Student::factory()->create();
    $permissions = ['view_batch', 'view_any_enrollment'];

    ($this->mountEnrollments)(($this->actorWith)(...$permissions, ...['create_enrollment']))
        ->callTableAction('enroll', null, ['student_id' => $student->getKey()]);

    expect(batchScreenSentNotifications())->toBe([batchScreenSuccess('Student enrolled.')])
        ->and(Enrollment::query()->count())->toBe(1);

    ($this->crafted)(($this->mountEnrollments)(($this->actorWith)(...$permissions)), 'enroll', null, [
        'student_id' => Student::factory()->create()->getKey(),
    ]);

    expect(batchScreenSentNotifications())->toBe([])
        ->and(Enrollment::query()->count())->toBe(1);
});

it('sends the over-capacity warning after the enrolment success toast, not instead of it', function () {
    $full = Batch::factory()->for($this->course)->active()->create(['capacity' => 1]);
    Enrollment::factory()->for($full)->create();

    ($this->mountEnrollments)(($this->actorWith)('view_batch', 'view_any_enrollment', 'create_enrollment'), $full)
        ->callTableAction('enroll', null, ['student_id' => Student::factory()->create()->getKey()]);

    expect(batchScreenSentNotifications())->toBe([
        batchScreenSuccess('Student enrolled.'),
        ['title' => 'This batch is now over capacity.', 'status' => 'warning', 'persistent' => true],
    ])->and($full->enrollments()->count())->toBe(2);
});

it('tells the reader a student was withdrawn, and an actor one grant short gets no write and no toast', function () {
    $enrollment = Enrollment::factory()->for($this->batch)->create();
    $permissions = ['view_batch', 'view_any_enrollment'];

    ($this->crafted)(($this->mountEnrollments)(($this->actorWith)(...$permissions)), 'withdraw', $enrollment);

    expect(batchScreenSentNotifications())->toBe([])
        ->and($enrollment->fresh()?->status)->toBe(EnrollmentStatus::Active);

    ($this->mountEnrollments)(($this->actorWith)(...$permissions, ...['update_enrollment']))
        ->callTableAction('withdraw', $enrollment);

    expect(batchScreenSentNotifications())->toBe([batchScreenSuccess('Student withdrawn.')])
        ->and($enrollment->fresh()?->status)->toBe(EnrollmentStatus::Withdrawn);
});

it('tells the reader an enrolment was deleted, and an actor one grant short gets no write and no toast', function () {
    $enrollment = Enrollment::factory()->for($this->batch)->create();
    $permissions = ['view_batch', 'view_any_enrollment'];

    ($this->crafted)(($this->mountEnrollments)(($this->actorWith)(...$permissions)), 'delete', $enrollment);

    expect(batchScreenSentNotifications())->toBe([])
        ->and(Enrollment::query()->whereKey($enrollment->getKey())->exists())->toBeTrue();

    ($this->mountEnrollments)(($this->actorWith)(...$permissions, ...['delete_enrollment']))
        ->callTableAction('delete', $enrollment);

    expect(batchScreenSentNotifications())->toBe([batchScreenSuccess('Enrolment deleted.')])
        ->and(Enrollment::query()->whereKey($enrollment->getKey())->exists())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| P3-4: a refusal keeps its own toast and gets no success beside it
|--------------------------------------------------------------------------
*/

it('refuses a departed instructor\'s hours with the refusal toast alone', function () {
    $omar = ($this->instructor)('Omar');
    $this->batch->instructors()->attach([$omar->getKey() => ['assigned_hours' => 12]]);
    $omar->delete();

    ($this->mountInstructors)(($this->actorWith)('view_batch', 'assign_instructor'))
        ->callTableAction('edit_hours', $omar, ['assigned_hours' => 40]);

    expect(batchScreenSentNotifications())->toBe([
        batchScreenRefusal('This person is not an eligible instructor.'),
    ])->and((int) DB::table('batch_instructor')->value('assigned_hours'))->toBe(12);
});

it('keeps the closed-batch refusal on enrol, with no success toast and nothing written', function () {
    // "Enrol student" is hidden on a closed batch, so the Action is stood in for:
    // this is the toast a batch closing while the enrol dialog is open would send.
    ($this->refusingAction)(EnrollAndBillAction::class, new BatchClosedException(1));

    ($this->mountEnrollments)(($this->actorWith)('view_batch', 'view_any_enrollment', 'create_enrollment'))
        ->callTableAction('enroll', null, ['student_id' => Student::factory()->create()->getKey()]);

    expect(batchScreenSentNotifications())->toBe([
        batchScreenRefusal('Completed and cancelled batches take no new enrolments and no instructor changes.'),
    ])->and(Enrollment::query()->count())->toBe(0);
});

it('keeps the closed-batch refusal on assign and remove, with no success toast', function () {
    // Both buttons are now hidden on a closed batch, so the Actions are stood in
    // for: this is the toast a race between the check and the lock would send.
    ($this->refusingAction)(AssignInstructorAction::class, new BatchClosedException(1));
    ($this->refusingAction)(RemoveInstructorAction::class, new BatchClosedException(1));

    $sara = ($this->instructor)('Sara');
    $this->batch->instructors()->attach([$sara->getKey() => ['assigned_hours' => 18]]);
    $panel = ($this->mountInstructors)(($this->actorWith)('view_batch', 'assign_instructor'));

    $panel->callTableAction('assign', null, ['user_id' => $sara->getKey(), 'assigned_hours' => 5]);

    expect(batchScreenSentNotifications())->toBe([
        batchScreenRefusal('Completed and cancelled batches take no new enrolments and no instructor changes.'),
    ]);

    $panel->callTableAction('remove', $sara);

    expect(batchScreenSentNotifications())->toBe([
        batchScreenRefusal('Completed and cancelled batches take no new enrolments and no instructor changes.'),
    ]);
});

it('refuses a student already on the batch with the refusal toast alone', function () {
    $student = Student::factory()->create();
    Enrollment::factory()->for($this->batch)->for($student)->create();

    ($this->mountEnrollments)(($this->actorWith)('view_batch', 'view_any_enrollment', 'create_enrollment'))
        ->callTableAction('enroll', null, ['student_id' => $student->getKey()]);

    expect(batchScreenSentNotifications())->toBe([
        batchScreenRefusal('This student is already enrolled in this batch.'),
    ])->and(Enrollment::query()->count())->toBe(1);
});

it('keeps the withdraw refusal for a row that stopped being active under the reader, with no success toast', function () {
    // The button is now only on an active row, so the Action is stood in for.
    $enrollment = Enrollment::factory()->for($this->batch)->create();
    ($this->refusingAction)(WithdrawEnrollmentAction::class, new EnrollmentNotWithdrawableException((int) $enrollment->getKey()));

    ($this->mountEnrollments)(($this->actorWith)('view_batch', 'view_any_enrollment', 'update_enrollment'))
        ->callTableAction('withdraw', $enrollment);

    expect(batchScreenSentNotifications())->toBe([
        batchScreenRefusal('Only an active enrolment can be withdrawn.'),
    ]);
});

it('refuses to delete an enrolment whose bill carries a payment, with the refusal toast alone', function () {
    $student = Student::factory()->create();
    $enrollment = Enrollment::factory()->for($this->batch)->for($student)->create();
    ($this->pay)(($this->bill)($enrollment, '500.000'), $student, '100.000');

    ($this->mountEnrollments)(($this->actorWith)('view_batch', 'view_any_enrollment', 'delete_enrollment'))
        ->callTableAction('delete', $enrollment);

    expect(batchScreenSentNotifications())->toBe([
        batchScreenRefusal('This enrolment cannot be deleted: a payment has already been recorded against its bill.'),
    ])->and(Enrollment::query()->whereKey($enrollment->getKey())->exists())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| P3-5: only what can work is offered
|--------------------------------------------------------------------------
*/

it('hides "Enrol student" on a batch that takes no enrolments and offers it on one that does', function (BatchStatus $status, bool $offered) {
    $batch = Batch::factory()->for($this->course)->create(['status' => $status]);
    $actor = ($this->actorWith)('view_batch', 'view_any_enrollment', 'create_enrollment');

    $panel = ($this->mountEnrollments)($actor, $batch);

    $offered ? $panel->assertTableActionVisible('enroll') : $panel->assertTableActionHidden('enroll');

    // A request that skips the button gets nowhere on a closed batch.
    if (! $offered) {
        ($this->crafted)($panel, 'enroll', null, ['student_id' => Student::factory()->create()->getKey()]);
        expect(Enrollment::query()->count())->toBe(0)
            ->and(batchScreenSentNotifications())->toBe([]);
    }
})->with([
    'a planned batch' => [BatchStatus::Planned, true],
    'an active batch' => [BatchStatus::Active, true],
    'a completed batch' => [BatchStatus::Completed, false],
    'a cancelled batch' => [BatchStatus::Cancelled, false],
]);

it('shows "Withdraw" only on an active enrolment, and only to an actor who may amend', function (EnrollmentStatus $status, bool $offered) {
    $enrollment = Enrollment::factory()->for($this->batch)->create(['status' => $status]);

    $panel = ($this->mountEnrollments)(($this->actorWith)('view_batch', 'view_any_enrollment', 'update_enrollment'));

    $offered ? $panel->assertTableActionVisible('withdraw', $enrollment) : $panel->assertTableActionHidden('withdraw', $enrollment);

    // One grant fewer: hidden on the very row the control offers it on.
    if ($offered) {
        ($this->mountEnrollments)(($this->actorWith)('view_batch', 'view_any_enrollment'))
            ->assertTableActionHidden('withdraw', $enrollment);
    }
})->with([
    'an active enrolment' => [EnrollmentStatus::Active, true],
    'a completed enrolment' => [EnrollmentStatus::Completed, false],
    'a withdrawn enrolment' => [EnrollmentStatus::Withdrawn, false],
]);

/*
|--------------------------------------------------------------------------
| P3-7: Outstanding is this enrolment's own
|--------------------------------------------------------------------------
*/

it('shows each batch its own enrolment\'s outstanding, never the student\'s whole balance', function () {
    $student = Student::factory()->create();
    $first = Batch::factory()->for($this->course)->active()->create();
    $second = Batch::factory()->for($this->course)->active()->create();

    $firstEnrollment = Enrollment::factory()->for($first)->for($student)->create();
    $secondEnrollment = Enrollment::factory()->for($second)->for($student)->create();

    $firstCharge = ($this->bill)($firstEnrollment, '1200.000');
    ($this->bill)($secondEnrollment, '899.000');
    ($this->pay)($firstCharge, $student, '100.125');

    $actor = ($this->actorWith)('view_batch', 'view_any_enrollment', 'view_any_charge');

    // 1200.000 - 100.125 = 1099.875, and the second bill is untouched.
    ($this->mountEnrollments)($actor, $first)
        ->assertTableColumnStateSet('outstanding_amount', '1099.875', $firstEnrollment)
        ->assertSee('1099.875 LYD')
        ->assertDontSee('1998.875')
        ->assertDontSee('899.000');

    ($this->mountEnrollments)($actor, $second)
        ->assertTableColumnStateSet('outstanding_amount', '899.000', $secondEnrollment)
        ->assertSee('899.000 LYD')
        ->assertDontSee('1998.875')
        ->assertDontSee('1099.875');

    // The two figures still add up to the student's whole balance: 1099.875 + 899.000.
    expect(app(StudentBalanceQuery::class)->forStudent((int) $student->getKey())->total->toDecimal())->toBe('1998.875');
});

it('counts a written-off bill and ignores a reversed payment, the way the student balance does', function () {
    $student = Student::factory()->create();
    $writtenOffBatch = Batch::factory()->for($this->course)->active()->create();
    $reversedBatch = Batch::factory()->for($this->course)->active()->create();

    $writtenOffEnrollment = Enrollment::factory()->for($writtenOffBatch)->for($student)->create();
    $reversedEnrollment = Enrollment::factory()->for($reversedBatch)->for($student)->create();

    Charge::factory()->writtenOff()->create([
        'enrollment_id' => $writtenOffEnrollment->getKey(),
        'list_price' => '300.000',
    ]);
    $reversedCharge = ($this->bill)($reversedEnrollment, '400.000');
    ($this->pay)($reversedCharge, $student, '50.000', reversed: true);
    ($this->pay)($reversedCharge, $student, '25.250');

    $actor = ($this->actorWith)('view_batch', 'view_any_enrollment', 'view_any_charge');

    // Writing off does not pay: 300.000 stays owed.
    ($this->mountEnrollments)($actor, $writtenOffBatch)
        ->assertTableColumnStateSet('outstanding_amount', '300.000', $writtenOffEnrollment);

    // 400.000 less the live 25.250; the reversed 50.000 drops out.
    ($this->mountEnrollments)($actor, $reversedBatch)
        ->assertTableColumnStateSet('outstanding_amount', '374.750', $reversedEnrollment);

    // 300.000 + 374.750, as StudentBalanceQuery counts the same two bills.
    expect(app(StudentBalanceQuery::class)->forStudent((int) $student->getKey())->total->toDecimal())->toBe('674.750');
});

it('shows an enrolment with no bill as owing nothing', function () {
    $enrollment = Enrollment::factory()->for($this->batch)->create();

    ($this->mountEnrollments)(($this->actorWith)('view_batch', 'view_any_enrollment', 'view_any_charge'))
        ->assertTableColumnStateSet('outstanding_amount', '0.000', $enrollment)
        ->assertSee('0.000 LYD');
});

it('hides the outstanding column, and never reads a balance, from an actor without view_any_charge', function () {
    $student = Student::factory()->create();
    $enrollment = Enrollment::factory()->for($this->batch)->for($student)->create();
    ($this->pay)(($this->bill)($enrollment, '500.000'), $student, '100.125');

    $statements = captureStatements();

    ($this->mountEnrollments)(($this->actorWith)('view_batch', 'view_any_enrollment'))
        ->assertTableColumnHidden('outstanding_amount')
        ->assertDontSee('399.875');

    expect(collect($statements)->filter(fn (array $statement): bool => str_contains($statement['sql'], 'payment_allocations')))->toBeEmpty();

    ($this->mountEnrollments)(($this->actorWith)('view_batch', 'view_any_enrollment', 'view_any_charge'))
        ->assertTableColumnVisible('outstanding_amount')
        ->assertSee('399.875 LYD');
});

it('keeps the outstanding figure linking to the charges filtered to that student', function () {
    $student = Student::factory()->create();
    $enrollment = Enrollment::factory()->for($this->batch)->for($student)->create();
    ($this->bill)($enrollment, '500.000');

    $actor = ($this->actorWith)('view_batch', 'view_any_enrollment', 'view_any_charge', 'view_student');

    $url = ($this->mountEnrollments)($actor)->instance()->getTable()
        ->getColumn('outstanding_amount')->record($enrollment)->getUrl();

    expect(urldecode((string) $url))->toContain('/charges')
        ->and(urldecode((string) $url))->toContain("filters[student][id]={$student->getKey()}");
});

it('reads the same number of statements for one enrolment row as for five', function () {
    $actor = ($this->actorWith)('view_batch', 'view_any_enrollment', 'view_any_charge', 'view_student');

    $measure = function (int $rows) use ($actor): int {
        $batch = Batch::factory()->for($this->course)->active()->create();

        foreach (range(1, $rows) as $_) {
            $student = Student::factory()->create();
            $enrollment = Enrollment::factory()->for($batch)->for($student)->create();
            ($this->pay)(($this->bill)($enrollment, '500.000'), $student, '100.125');
        }

        $statements = captureStatements();

        // 500.000 - 100.125, on every row: the column rendered, it was not skipped.
        ($this->mountEnrollments)($actor, $batch)->assertSuccessful()->assertSee('399.875 LYD');

        return count($statements);
    };

    // The first mount of a process warms permission and policy caches.
    ($this->mountEnrollments)($actor)->assertSuccessful();

    $one = $measure(1);
    $five = $measure(5);

    expect($one)->toBeGreaterThan(0)
        ->and($five)->toBe($one);
});
