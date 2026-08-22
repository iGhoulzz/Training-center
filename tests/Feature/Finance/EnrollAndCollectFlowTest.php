<?php

declare(strict_types=1);

use App\Domain\Enrollment\Enums\BatchStatus;
use App\Domain\Enrollment\Enums\StudentStatus;
use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\Student;
use App\Domain\Finance\Enums\TenderMethod;
use App\Domain\Finance\Exceptions\DiscountNotApplicableException;
use App\Domain\Finance\Filament\Pages\EnrollAndCollect;
use App\Domain\Finance\Models\Charge;
use App\Domain\Finance\Models\Discount;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Models\PaymentAllocation;
use App\Domain\Finance\Models\PaymentReceiptSnapshot;
use App\Domain\Finance\Models\PaymentTender;
use App\Domain\Finance\Support\ChargeBalance;
use App\Domain\Finance\Support\Reference;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| EnrollAndCollect — the page shell, the panel seam, and steps 1-2 (P2-T09,
| unit 1)
|--------------------------------------------------------------------------
|
| Design section 2: phase 2's primary surface is one guided eight-step flow,
| not a set of CRUD screens the operator assembles. This unit builds only the
| page shell, the discoverPages() seam in AdminPanelProvider, and the first
| two steps — find or create the student, and select the batch. Steps 3
| through 8 (discount, preview, confirm, collection, tenders, receipt) are
| later, test-led units and are not covered here.
|
| Every test below drives the REAL Livewire component and the REAL HTTP
| route, following ChargeResourceTest's own reasoning: a policy assertion
| alone proves nothing about whether the class is even discovered, whether it
| extends the right base class, or whether the panel seam was wired up.
*/
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    $this->system = app(SystemRoleWriter::class);

    $this->actorWith = function (string $role): User {
        $user = User::factory()->create(['is_active' => true]);
        $this->system->assignRoles($user, $role);

        return $user->refresh();
    };

    // Staff hold create_enrollment explicitly (RolePermissionSeeder) — a
    // front-desk actor enrolling a walk-in is exactly who this page is for.
    $this->staff = ($this->actorWith)('staff');

    // Admin and above hold apply_discount (design section 3) — the actor who
    // may choose a discount at all, and the positive control for the
    // discount-step tests below.
    $this->admin = ($this->actorWith)('admin');

    /*
     * An actor who can reach the admin panel at all, but holds nothing named
     * create_enrollment. Neither seeded role fits this: 'staff' holds
     * create_enrollment outright, 'admin' inherits it from crudFor('enrollment'),
     * and 'student' holds no permission at all — including access_admin_panel —
     * so a refusal there would prove nothing about THIS gate specifically. A
     * bespoke role isolates the one ability under test, the same reasoning
     * AdjustChargeTest and WriteOffChargeTest use when they grant a permission
     * ad hoc to prove a policy still refuses.
     */
    $role = Role::findOrCreate('front_desk_without_enroll', 'web');
    $this->system->syncRolePermissions($role, ['access_admin_panel']);
    $this->noAbility = ($this->actorWith)('front_desk_without_enroll');
});

/*
|--------------------------------------------------------------------------
| The page is discovered and reachable
|--------------------------------------------------------------------------
*/

it('is discovered and reachable by an actor holding create_enrollment', function () {
    expect($this->staff->can('create_enrollment'))->toBeTrue();

    Livewire::actingAs($this->staff)
        ->test(EnrollAndCollect::class)
        ->assertOk();

    $this->actingAs($this->staff)
        ->get('/admin/enroll-and-collect')
        ->assertSuccessful();
});

/*
|--------------------------------------------------------------------------
| An actor without the ability is refused
|--------------------------------------------------------------------------
*/

it('refuses an actor without create_enrollment, through the real component and the route', function () {
    expect($this->noAbility->can('access_admin_panel'))->toBeTrue()
        ->and($this->noAbility->can('create_enrollment'))->toBeFalse();

    Livewire::actingAs($this->noAbility)
        ->test(EnrollAndCollect::class)
        ->assertForbidden();

    $this->actingAs($this->noAbility)
        ->get('/admin/enroll-and-collect')
        ->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| Step 1 — find or create the student
|--------------------------------------------------------------------------
|
| searchStudents() and createStudent() are public and static for the same
| reason EnrollmentsRelationManager::searchStudents() already is: reaching
| them through a mounted schema's search-results call means asserting
| against Filament's component internals, which change between releases and
| would make the test a statement about the framework rather than about the
| search. Both are also exactly the closures the page's Select registers, so
| testing them directly tests the real behaviour, not a stand-in for it.
*/

it('finds an existing student by code or name, and not one that does not match', function () {
    $match = Student::factory()->create([
        'student_code' => 'STU-100',
        'first_name' => 'Amina',
        'last_name' => 'Zarrouk',
    ]);

    $other = Student::factory()->create([
        'student_code' => 'STU-200',
        'first_name' => 'Youssef',
        'last_name' => 'Saleh',
    ]);

    $byName = EnrollAndCollect::searchStudents('Zarrouk');
    $byCode = EnrollAndCollect::searchStudents('STU-100');

    expect($byName)->toHaveKey($match->getKey())
        ->and($byName)->not->toHaveKey($other->getKey())
        ->and($byCode)->toHaveKey($match->getKey());
});

it('creates a new student inline, as a prospective student', function () {
    $id = EnrollAndCollect::createStudent([
        'student_code' => 'STU-300',
        'first_name' => 'New',
        'last_name' => 'Walkin',
        'phone' => '0910000000',
    ]);

    $student = Student::query()->findOrFail($id);

    expect($student->student_code)->toBe('STU-300')
        ->and($student->first_name)->toBe('New')
        ->and($student->last_name)->toBe('Walkin')
        ->and($student->phone)->toBe('0910000000')
        ->and($student->status)->toBe(StudentStatus::Prospective);
});

/*
|--------------------------------------------------------------------------
| Step 2 — select the batch, offering only ones that still accept enrolments
|--------------------------------------------------------------------------
|
| Batch::scopeOpen()'s own docblock: kept in step with acceptsEnrollments()
| by BatchTest, so reading it here rather than re-deriving the predicate is
| what keeps this page and that rule from drifting apart. Driven through the
| REAL mounted schema — getFlatFields() recurses into the Wizard's Step
| containers — so this proves the field the operator actually sees, not a
| query built to look like it in the test.
*/

it('lists in step two only batches that still accept enrolments', function () {
    $planned = Batch::factory()->create(['status' => BatchStatus::Planned]);
    $active = Batch::factory()->create(['status' => BatchStatus::Active]);
    $completed = Batch::factory()->create(['status' => BatchStatus::Completed]);
    $cancelled = Batch::factory()->create(['status' => BatchStatus::Cancelled]);

    $livewire = Livewire::actingAs($this->staff)
        ->test(EnrollAndCollect::class)
        ->instance();

    $fields = $livewire->getSchema('form')->getFlatFields();

    expect($fields)->toHaveKey('batch_id');

    $options = $fields['batch_id']->getOptions();

    expect($options)->toHaveKey($planned->getKey())
        ->and($options)->toHaveKey($active->getKey())
        ->and($options)->not->toHaveKey($completed->getKey())
        ->and($options)->not->toHaveKey($cancelled->getKey());
});

/*
|--------------------------------------------------------------------------
| Staff never see the word "allocation"
|--------------------------------------------------------------------------
|
| Design section 2 states this outright — and it is a rule about what staff
| SEE, which is what this test scans: the page's STRING LITERALS, the view's
| markup, and the translation catalogue's VALUES.
|
| IT DELIBERATELY DOES NOT SCAN IDENTIFIERS, AND AN EARLIER VERSION DID.
| That version ran mb_stripos() over the whole comment-stripped source, so
| any symbol containing the substring failed it — including
| `TenderAllocationMismatchException`, the typed refusal this page has to
| catch, and `RecordPaymentData`'s own `allocation:` named argument. Both
| belong to task 4 and cannot be renamed from here, so the over-broad scan
| pushed this page into positional arguments and away from the catch-and-
| notify pattern `ChargeResource::adjustAction()` establishes — a test
| dictating implementation rather than protecting a property.
|
| Its own header already claimed it scanned "the class's real string
| literals". It did not. Narrowing the scan to string literals is what makes
| that sentence true, and a class name in a catch clause is not something a
| member of staff can read.
*/

/**
 * Every string literal in a PHP file, with identifiers, keywords and
 * comments left out.
 *
 * A named function with its own samples below, rather than an inline regex:
 * an inline pattern can only ever be tested against the file as it happens
 * to be today, which is exactly the case where it passes vacuously.
 *
 * @return list<string>
 */
function stringLiteralsIn(string $path): array
{
    $literals = [];

    foreach (token_get_all((string) file_get_contents($path)) as $token) {
        if (! is_array($token)) {
            continue;
        }

        if (in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML], true)) {
            $literals[] = $token[1];
        }
    }

    return $literals;
}

it('extracts string literals and not identifiers', function () {
    // The detector's own samples. Without these it could silently start
    // matching nothing and this file's central rule would pass vacuously.
    $sample = tempnam(sys_get_temp_dir(), 'lit').'.php';
    file_put_contents($sample, "<?php\nclass AllocationThing { public function f() { return 'visible copy'; } }\n");

    $literals = stringLiteralsIn($sample);
    unlink($sample);

    expect($literals)->toContain("'visible copy'")
        ->and(implode(' ', $literals))->not->toContain('AllocationThing');
});

it('never says "allocation" in code or copy a user could see', function () {
    foreach (stringLiteralsIn(app_path('Domain/Finance/Filament/Pages/EnrollAndCollect.php')) as $literal) {
        expect(mb_stripos($literal, 'allocation'))->toBe(
            false,
            "A string literal on the page mentions allocation: {$literal}",
        );
    }

    $viewPath = resource_path('views/filament/finance/enroll-and-collect.blade.php');

    /*
     * NOT CONDITIONAL. An earlier version wrapped this in is_file(), so
     * renaming or moving the view silently stopped the assertion running
     * rather than failing — a guard that disappears when the thing it guards
     * moves. The view is required for the page to render at all, so its
     * absence is itself a failure.
     */
    expect(is_file($viewPath))->toBeTrue("The page's view is missing: {$viewPath}");

    expect(mb_stripos((string) file_get_contents($viewPath), 'allocation'))->toBe(false);

    /*
     * BOTH CATALOGUES. `ar` is empty until phase 4, which makes scanning it
     * vacuous today and load-bearing the day it is filled — the point at
     * which nobody will remember this rule exists.
     */
    $translations = [];

    foreach (['en', 'ar'] as $locale) {
        foreach ((array) require lang_path("{$locale}/collect.php") as $key => $value) {
            $translations["{$locale}.{$key}"] = $value;
        }
    }

    foreach ($translations as $key => $value) {
        expect(mb_stripos((string) $value, 'allocation'))->toBe(
            false,
            "Translation collect.{$key} mentions allocation: \"{$value}\"",
        );
    }
});

/*
|--------------------------------------------------------------------------
| EnrollAndCollect — steps 3-5 (P2-T09, unit 2)
|--------------------------------------------------------------------------
|
| Design section 2, steps 3 through 5: an optional enrolment discount gated
| on apply_discount, a preview of the price, and confirm — which creates the
| enrolment and the bill in one transaction. Steps 6 through 8 (collect,
| tenders, receipt) are a later unit and are not covered here.
*/

/*
|--------------------------------------------------------------------------
| Step 3 — the discount step is invisible without apply_discount
|--------------------------------------------------------------------------
|
| Design sections 2 and 10: staff enrol walk-ins at full price and hold no
| financial ability. The step must be HIDDEN, not merely disabled — Filament's
| own assertFormFieldHidden()/assertFormFieldVisible() read the schema the
| same way the real render does (getFlatFields(withHidden: false)), so this
| proves the field the operator actually gets, not a stand-in for it.
*/

it('hides the discount step from an actor without apply_discount, and shows it to one who holds it', function () {
    expect($this->staff->can('apply_discount'))->toBeFalse();

    Livewire::actingAs($this->staff)
        ->test(EnrollAndCollect::class)
        ->assertFormFieldHidden('discount_id');

    expect($this->admin->can('apply_discount'))->toBeTrue();

    Livewire::actingAs($this->admin)
        ->test(EnrollAndCollect::class)
        ->assertFormFieldVisible('discount_id');
});

/*
|--------------------------------------------------------------------------
| A crafted submission is still refused server-side
|--------------------------------------------------------------------------
|
| Hiding the step is a courtesy, not the guard (EnrollAndBillAction's own
| docblock). confirm() is called directly on the real, fully-booted
| component instance — not through Livewire's HTTP-simulating ->call(), which
| would let Laravel's exception handler convert the AuthorizationException
| into a response before this test ever saw it, the same reason
| ->assertForbidden() works for canAccess() above. Calling the method
| directly is what lets this assert the concrete exception type, exactly as
| EnrollAndBillTest does for the Action itself.
*/

it('refuses a crafted discount from an actor without apply_discount, even though the step never rendered for them', function () {
    $batch = Batch::factory()->create(['price' => '1000.000']);
    $discount = Discount::factory()->create(['percentage' => '25.00']);
    $student = Student::factory()->create();

    expect($this->staff->can('apply_discount'))->toBeFalse();

    $component = Livewire::actingAs($this->staff)
        ->test(EnrollAndCollect::class)
        ->fillForm([
            'student_id' => $student->getKey(),
            'batch_id' => $batch->getKey(),
        ]);

    // A crafted client-side state update: the field this actor never saw.
    $component->set('data.discount_id', $discount->getKey());

    /*
     * THE REFUSAL IS SHOWN, NOT THROWN — AND THE ASSERTION FOLLOWS THE FIX.
     *
     * This test originally asserted that `AuthorizationException` escaped
     * `confirm()`. The independent pre-PR review found that escaping was
     * itself the defect: uncaught, it is a 500 on the phase's primary
     * surface. `confirm()` now refuses through the notification pattern
     * `EnrollmentsRelationManager::refuse()` established for the identical
     * Action, so the property pinned here is the one that always mattered —
     * the crafted discount is refused and NOTHING is written.
     *
     * `Gate::forUser($actor)->authorize('apply_discount')` inside
     * `EnrollAndBillAction` is still what refuses it; this page only decides
     * how the operator hears about it.
     */
    $component->instance()->confirm();

    FilamentNotification::assertNotified(__('collect.enrollment_denied'));

    expect(Enrollment::query()->count())->toBe(
        0,
        'A crafted discount from an actor without apply_discount created an enrolment.',
    )->and(Charge::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Step 4 — the preview
|--------------------------------------------------------------------------
|
| Called directly, the same testability reasoning as searchStudents() and
| createStudent(): these are the exact closures the preview's TextEntry
| components call, so testing them directly tests the real behaviour rather
| than a stand-in for it. Literals throughout — a money assertion against a
| random factory default is vacuous.
*/

it('previews the list price, the discount percentage and the final amount', function () {
    $batch = Batch::factory()->create(['price' => '1000.000']);
    $discount = Discount::factory()->create(['percentage' => '10.00']);

    expect(EnrollAndCollect::previewListPrice($batch->getKey())?->toDecimal())->toBe('1000.000')
        ->and(EnrollAndCollect::previewDiscount($discount->getKey())?->percentage)->toBe('10.00')
        ->and(EnrollAndCollect::previewFinalAmount($batch->getKey(), $discount->getKey())?->toDecimal())->toBe('900.000');
});

it('previews the full list price as the final amount when no discount is chosen', function () {
    $batch = Batch::factory()->create(['price' => '640.000']);

    expect(EnrollAndCollect::previewListPrice($batch->getKey())?->toDecimal())->toBe('640.000')
        ->and(EnrollAndCollect::previewDiscount(null))->toBeNull()
        ->and(EnrollAndCollect::previewFinalAmount($batch->getKey(), null)?->toDecimal())->toBe('640.000');
});

/*
|--------------------------------------------------------------------------
| The preview figure equals the figure actually billed
|--------------------------------------------------------------------------
|
| 216.350 at 1.00%, not 1000 at 10% — the same case EnrollAndBillTest and
| ChargeResourceTest use for exactly this reason: 1000 at 10% divides
| exactly, so a hand-rolled float implementation would still land on 900.000
| and this test would pass for the wrong reason. 216.350 at 1.00% is
| 214.1865 dirham, which only a correct half-up implementation lands on
| 214.187 — proof obligation 1 replaces the preview's Money::afterDiscount()
| call with a float calculation and this is the case that has to fail.
*/

it('bills exactly the figure the preview showed', function () {
    $batch = Batch::factory()->create(['price' => '216.350']);
    $discount = Discount::factory()->create(['percentage' => '1.00']);
    $student = Student::factory()->create();

    $previewed = EnrollAndCollect::previewFinalAmount($batch->getKey(), $discount->getKey());

    expect($previewed?->toDecimal())->toBe('214.187');

    Livewire::actingAs($this->admin)
        ->test(EnrollAndCollect::class)
        ->fillForm([
            'student_id' => $student->getKey(),
            'batch_id' => $batch->getKey(),
            'discount_id' => $discount->getKey(),
        ])
        ->call('confirm');

    $charge = Charge::query()->firstOrFail();

    expect($charge->amount)->toBe($previewed->toDecimal())
        ->and($charge->amount)->toBe('214.187');
});

/*
|--------------------------------------------------------------------------
| Step 5 — confirm creates exactly one enrolment and one charge
|--------------------------------------------------------------------------
*/

it('confirms exactly one enrolment and one charge, in one transaction, with real references and no placeholder left behind', function () {
    $batch = Batch::factory()->create(['price' => '500.000']);
    $student = Student::factory()->create();

    Livewire::actingAs($this->staff)
        ->test(EnrollAndCollect::class)
        ->fillForm([
            'student_id' => $student->getKey(),
            'batch_id' => $batch->getKey(),
        ])
        ->call('confirm');

    expect(Enrollment::query()->count())->toBe(1)
        ->and(Charge::query()->count())->toBe(1);

    $enrollment = Enrollment::query()->firstOrFail();
    $charge = Charge::query()->firstOrFail();

    expect($enrollment->reference)->toStartWith(Reference::ENROLLMENT_PREFIX)
        ->and(Reference::isPlaceholder($enrollment->reference))->toBeFalse()
        ->and($charge->reference)->toStartWith(Reference::CHARGE_PREFIX)
        ->and(Reference::isPlaceholder($charge->reference))->toBeFalse()
        ->and($charge->enrollment_id)->toBe($enrollment->getKey());
});

/*
|--------------------------------------------------------------------------
| Confirm with no discount bills the full list price — the staff walk-in
|--------------------------------------------------------------------------
*/

it('bills the full list price when staff confirm with no discount chosen', function () {
    $batch = Batch::factory()->create(['price' => '750.000']);
    $student = Student::factory()->create();

    expect($this->staff->can('create_enrollment'))->toBeTrue()
        ->and($this->staff->can('apply_discount'))->toBeFalse();

    Livewire::actingAs($this->staff)
        ->test(EnrollAndCollect::class)
        ->fillForm([
            'student_id' => $student->getKey(),
            'batch_id' => $batch->getKey(),
        ])
        ->call('confirm');

    $charge = Charge::query()->firstOrFail();

    expect($charge->list_price)->toBe('750.000')
        ->and($charge->amount)->toBe('750.000')
        ->and($charge->discount_id)->toBeNull()
        ->and($charge->discount_percentage)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| A retired discount is not offered by the picker, and is refused if
| submitted anyway
|--------------------------------------------------------------------------
*/

it('does not offer a retired discount in the picker, and refuses it server-side if submitted anyway', function () {
    $active = Discount::factory()->create(['percentage' => '10.00']);
    $retired = Discount::factory()->inactive()->create(['percentage' => '50.00']);

    $livewire = Livewire::actingAs($this->admin)
        ->test(EnrollAndCollect::class)
        ->instance();

    $fields = $livewire->getSchema('form')->getFlatFields();

    expect($fields)->toHaveKey('discount_id');

    $options = $fields['discount_id']->getOptions();

    expect($options)->toHaveKey($active->getKey())
        ->and($options)->not->toHaveKey($retired->getKey());

    $batch = Batch::factory()->create(['price' => '1000.000']);
    $student = Student::factory()->create();

    $component = Livewire::actingAs($this->admin)
        ->test(EnrollAndCollect::class)
        ->fillForm([
            'student_id' => $student->getKey(),
            'batch_id' => $batch->getKey(),
            'discount_id' => $retired->getKey(),
        ]);

    $thrown = null;

    try {
        $component->instance()->confirm();
    } catch (DiscountNotApplicableException $exception) {
        $thrown = $exception;
    }

    expect($thrown)->toBeInstanceOf(DiscountNotApplicableException::class)
        ->and($thrown->discountId)->toBe((int) $retired->getKey());

    expect(Enrollment::query()->count())->toBe(0)
        ->and(Charge::query()->count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| EnrollAndCollect — steps 6-8 (P2-T09, unit 3, final)
|--------------------------------------------------------------------------
|
| Design section 2, steps 6 through 8: optionally collect immediately (the
| full amount or an installment), cash/card/split tenders, and finalize —
| one receipt with its own number. `RecordPaymentAction` is the only path
| that records money; this page never allocates a payment to more than the
| one bill `confirm()` just raised, and never shows the operator that word.
|
| THE COLLECTION PANEL IS A SEPARATE SCHEMA, NOT MORE WIZARD STEPS
| ------------------------------------------------------------------
| `confirm()` already resets the enrol-and-bill Wizard back to step one so
| the desk can start the next walk-in immediately (unit 2's behaviour,
| unchanged). Collection is its own schema (`collectForm`, statePath
| `collect`), rendered only once `EnrollAndCollect::$collectionChargeId` is
| set — which `confirm()` does for an actor holding `create_payment`, and
| never does otherwise, mirroring the discount step's "hidden as a courtesy,
| refused for real by the Action's own Gate" shape.
|
| `finalize()` and `finishCollection()` are bare Livewire methods bound to
| plain Filament Actions (`->action('finalize')`), exactly the
| `confirmAction()`/`confirm()` shape already established in this file —
| not a modal-schema Action, because a bare string binds straight to
| `wire:click`, deliberately skipping Filament's mount/unmount action
| lifecycle. That is load-bearing: an Action that unmounts itself on success
| cannot be called a second time to prove idempotency, and `finalize()` must
| be callable twice in a row with no rerender between the calls.
|
| THE IDEMPOTENCY KEY IS NEVER RESET ON A SUCCESSFUL finalize()
| -----------------------------------------------------------------
| Design section 5: the key exists because a double-clicked collection
| button is otherwise two payments for one handover of cash. Clearing
| `collectionIdempotencyKey` the instant the first call succeeds would make
| a second, identical call re-mint a fresh key and defeat the very
| mechanism under test — so a second `finalize()` call keeps using the same
| key and lands on `RecordPaymentAction`'s replay path, returning the
| existing payment. Only `finishCollection()` (an explicit "Done" the
| operator reaches for once satisfied, whether or not they collected
| anything) clears it, starting the next enrolment's collection fresh.
*/

/*
|--------------------------------------------------------------------------
| Behaviour 1 — the full happy path, end to end through Livewire
|--------------------------------------------------------------------------
*/

it('runs the full happy path end to end through Livewire: enrol, bill, collect the full amount in cash, and finalize one receipt', function () {
    $batch = Batch::factory()->create(['price' => '1000.000']);
    $student = Student::factory()->create();

    $component = Livewire::actingAs($this->admin)
        ->test(EnrollAndCollect::class)
        ->fillForm([
            'student_id' => $student->getKey(),
            'batch_id' => $batch->getKey(),
        ])
        ->call('confirm');

    $charge = Charge::query()->firstOrFail();

    $component
        ->fillForm([
            'amount' => '1000.000',
            'tenders' => [
                ['method' => TenderMethod::Cash->value, 'amount' => '1000.000', 'external_reference' => null],
            ],
        ], 'collectForm')
        ->call('finalize')
        ->assertHasNoFormErrors([], 'collectForm');

    expect(Enrollment::query()->count())->toBe(1)
        ->and(Charge::query()->count())->toBe(1)
        ->and(Payment::query()->count())->toBe(1)
        ->and(PaymentTender::query()->count())->toBe(1)
        ->and(PaymentAllocation::query()->count())->toBe(1);

    $payment = Payment::query()->firstOrFail();

    expect($payment->reference)->toStartWith(Reference::PAYMENT_PREFIX)
        ->and(Reference::isPlaceholder($payment->reference))->toBeFalse();

    expect(ChargeBalance::outstandingFor($charge->getKey())->toDecimal())->toBe('0.000');
});

/*
|--------------------------------------------------------------------------
| Behaviour 2 — a split payment is one payment with two tenders
|--------------------------------------------------------------------------
*/

it('records a split payment of 300 on card and 700 in cash against a 1,000 bill as one payment with two tenders', function () {
    $batch = Batch::factory()->create(['price' => '1000.000']);
    $student = Student::factory()->create();

    $component = Livewire::actingAs($this->admin)
        ->test(EnrollAndCollect::class)
        ->fillForm([
            'student_id' => $student->getKey(),
            'batch_id' => $batch->getKey(),
        ])
        ->call('confirm');

    $component
        ->fillForm([
            'amount' => '1000.000',
            'tenders' => [
                ['method' => TenderMethod::Card->value, 'amount' => '300.000', 'external_reference' => 'AUTH-300'],
                ['method' => TenderMethod::Cash->value, 'amount' => '700.000', 'external_reference' => null],
            ],
        ], 'collectForm')
        ->call('finalize')
        ->assertHasNoFormErrors([], 'collectForm');

    expect(Payment::query()->count())->toBe(1)
        ->and(PaymentAllocation::query()->count())->toBe(1)
        ->and(PaymentTender::query()->count())->toBe(2);

    $tenders = PaymentTender::query()->orderBy('amount')->get();

    expect($tenders[0]->method)->toBe(TenderMethod::Card)
        ->and($tenders[0]->amount)->toBe('300.000')
        ->and($tenders[0]->external_reference)->toBe('AUTH-300')
        ->and($tenders[1]->method)->toBe(TenderMethod::Cash)
        ->and($tenders[1]->amount)->toBe('700.000');
});

/*
|--------------------------------------------------------------------------
| Behaviour 3 — an installment leaves the remainder outstanding
|--------------------------------------------------------------------------
*/

it('records an installment of 400 against a 1,000 bill and leaves 600.000 outstanding', function () {
    $batch = Batch::factory()->create(['price' => '1000.000']);
    $student = Student::factory()->create();

    Livewire::actingAs($this->admin)
        ->test(EnrollAndCollect::class)
        ->fillForm([
            'student_id' => $student->getKey(),
            'batch_id' => $batch->getKey(),
        ])
        ->call('confirm')
        ->fillForm([
            'amount' => '400.000',
            'tenders' => [
                ['method' => TenderMethod::Cash->value, 'amount' => '400.000', 'external_reference' => null],
            ],
        ], 'collectForm')
        ->call('finalize')
        ->assertHasNoFormErrors([], 'collectForm');

    $charge = Charge::query()->firstOrFail();

    expect(ChargeBalance::outstandingFor($charge->getKey())->toDecimal())->toBe('600.000');
});

/*
|--------------------------------------------------------------------------
| Behaviour 4 — a double submit produces exactly one payment and one receipt
|--------------------------------------------------------------------------
|
| Called on the resolved instance directly, TWICE, with no Livewire call
| (and therefore no rerender) between them — the same reasoning the crafted
| discount test above gives for calling confirm() directly: this is what
| proves the SAME idempotency key is used both times, rather than proving
| something about Filament's action-mounting lifecycle instead.
|
| THE AMOUNT COLLECTED IS DELIBERATELY WELL BELOW THE BILL
| ----------------------------------------------------------
| 200 of a 1,000 bill, not the full 1,000. If the amount collected equalled
| the whole bill, a SECOND payment carrying a genuinely different key (the
| exact shape proof obligation 1 introduces) would still be refused — by
| `PaymentExceedsOutstandingException`, because the first call would already
| have settled the bill in full — and this test would report one payment
| for the wrong reason: an overpayment guard, not the idempotency key.
| Leaving 800 of headroom is what makes "exactly one payment" a claim about
| the KEY rather than a claim that would hold anyway.
*/

it('produces exactly one payment and one receipt from a double submit of the same collection', function () {
    $batch = Batch::factory()->create(['price' => '1000.000']);
    $student = Student::factory()->create();

    $component = Livewire::actingAs($this->admin)
        ->test(EnrollAndCollect::class)
        ->fillForm([
            'student_id' => $student->getKey(),
            'batch_id' => $batch->getKey(),
        ])
        ->call('confirm');

    $component->fillForm([
        'amount' => '200.000',
        'tenders' => [
            ['method' => TenderMethod::Cash->value, 'amount' => '200.000', 'external_reference' => null],
        ],
    ], 'collectForm');

    $instance = $component->instance();
    $instance->finalize();
    $instance->finalize();

    expect(Payment::query()->count())->toBe(1)
        ->and(PaymentReceiptSnapshot::query()->count())->toBe(1)
        ->and(PaymentTender::query()->count())->toBe(1)
        ->and(PaymentAllocation::query()->count())->toBe(1);

    $charge = Charge::query()->firstOrFail();

    expect(ChargeBalance::outstandingFor($charge->getKey())->toDecimal())->toBe('800.000');
});

/*
|--------------------------------------------------------------------------
| Behaviour 5 — card tender references: blank-after-trim and PAN-shaped
|--------------------------------------------------------------------------
*/

it('refuses a card tender with a blank-after-trim terminal reference, and a PAN-shaped reference with the translated message', function () {
    $batch = Batch::factory()->create(['price' => '1000.000']);
    $student = Student::factory()->create();

    $component = Livewire::actingAs($this->admin)
        ->test(EnrollAndCollect::class)
        ->fillForm([
            'student_id' => $student->getKey(),
            'batch_id' => $batch->getKey(),
        ])
        ->call('confirm');

    // Blank after trim: three spaces, no visible characters.
    $component
        ->fillForm([
            'amount' => '1000.000',
            'tenders' => [
                ['method' => TenderMethod::Card->value, 'amount' => '1000.000', 'external_reference' => '   '],
            ],
        ], 'collectForm')
        ->call('finalize')
        ->assertHasFormErrors(['tenders.0.external_reference'], 'collectForm');

    expect(Payment::query()->count())->toBe(0);

    // PAN-shaped: 16 digits, grouped with spaces exactly as a card is printed.
    $component
        ->fillForm([
            'tenders' => [
                ['method' => TenderMethod::Card->value, 'amount' => '1000.000', 'external_reference' => '4111 1111 1111 1111'],
            ],
        ], 'collectForm')
        ->call('finalize')
        ->assertHasFormErrors(['tenders.0.external_reference'], 'collectForm');

    expect(Payment::query()->count())->toBe(0);

    $errors = $component->instance()->getErrorBag();

    expect($errors->first('collect.tenders.0.external_reference'))
        ->toBe(__('payments.not_a_card_number'));
});

/*
|--------------------------------------------------------------------------
| Behaviour 6 — collection is optional
|--------------------------------------------------------------------------
|
| Staff hold create_enrollment but not create_payment (RolePermissionSeeder):
| the collection panel never opens for them at all, the same "hidden as a
| courtesy" shape the discount step already uses for apply_discount. The
| enrolment and its bill exist in full; nothing about them depends on money
| ever being collected.
*/

it('leaves collection optional: confirming without collecting creates the enrolment and bill with the full amount outstanding', function () {
    $batch = Batch::factory()->create(['price' => '750.000']);
    $student = Student::factory()->create();

    expect($this->staff->can('create_payment'))->toBeFalse();

    Livewire::actingAs($this->staff)
        ->test(EnrollAndCollect::class)
        ->fillForm([
            'student_id' => $student->getKey(),
            'batch_id' => $batch->getKey(),
        ])
        ->call('confirm');

    expect(Enrollment::query()->count())->toBe(1)
        ->and(Charge::query()->count())->toBe(1)
        ->and(Payment::query()->count())->toBe(0);

    $charge = Charge::query()->firstOrFail();

    expect(ChargeBalance::outstandingFor($charge->getKey())->toDecimal())->toBe('750.000');
});

/*
|--------------------------------------------------------------------------
| Behaviour 7 — a tender/allocation mismatch is a field error, not a 500
|--------------------------------------------------------------------------
|
| `PaymentInvariantService::assertRecordable()` still owns this invariant
| under the charge's lock, exactly as design section 5 requires — but this
| page cannot import or catch `TenderAllocationMismatchException` BY NAME:
| the copy scan further up this file fails the build the instant a STRING
| LITERAL on this page contains the substring. It does not scan
| identifiers or comments, so a typed exception whose class name carries
| the word may be caught normally.
*/

it('surfaces a tender total that does not match the amount to collect as a field error, not an unhandled exception', function () {
    $batch = Batch::factory()->create(['price' => '1000.000']);
    $student = Student::factory()->create();

    $component = Livewire::actingAs($this->admin)
        ->test(EnrollAndCollect::class)
        ->fillForm([
            'student_id' => $student->getKey(),
            'batch_id' => $batch->getKey(),
        ])
        ->call('confirm');

    $component
        ->fillForm([
            'amount' => '1000.000',
            'tenders' => [
                ['method' => TenderMethod::Cash->value, 'amount' => '900.000', 'external_reference' => null],
            ],
        ], 'collectForm')
        ->call('finalize')
        ->assertHasFormErrors(['amount'], 'collectForm');

    expect(Payment::query()->count())->toBe(0);

    expect($component->instance()->getErrorBag()->first('collect.amount'))
        ->toBe(__('collect.tender_total_mismatch'));
});

/*
|--------------------------------------------------------------------------
| Refusals reach the operator, never the error page
|--------------------------------------------------------------------------
|
| Three defects the independent pre-PR review found, each an uncaught
| exception on the phase's primary surface. The Actions were refusing
| correctly the whole time; the page let the refusal escape as a 500, which
| reads to the operator as "the system is broken" rather than "that is not
| allowed" — and in the installment case silently lost the money.
*/

it('shows a refusal instead of a 500 when the student is already on the batch', function () {
    $batch = Batch::factory()->create(['price' => '1000.000']);
    $student = Student::factory()->create();

    // Already enrolled: the most ordinary error at the desk.
    Enrollment::factory()->create([
        'student_id' => $student->getKey(),
        'batch_id' => $batch->getKey(),
    ]);

    Livewire::actingAs($this->admin)
        ->test(EnrollAndCollect::class)
        ->fillForm([
            'student_id' => $student->getKey(),
            'batch_id' => $batch->getKey(),
        ])
        ->call('confirm');

    // DuplicateEnrollmentException's message, which its own docblock says
    // reaches the panel as a notification.
    FilamentNotification::assertNotified();

    // The existing enrolment is untouched and no second bill was raised.
    expect(Enrollment::query()->count())->toBe(
        1,
        'A duplicate enrolment was created instead of refused.',
    )->and(Charge::query()->count())->toBe(0);
});

it('refuses a second installment typed into the same collection panel, without losing it silently', function () {
    $batch = Batch::factory()->create(['price' => '1000.000']);
    $student = Student::factory()->create();

    $component = Livewire::actingAs($this->admin)
        ->test(EnrollAndCollect::class)
        ->fillForm([
            'student_id' => $student->getKey(),
            'batch_id' => $batch->getKey(),
        ])
        ->call('confirm');

    $charge = Charge::query()->firstOrFail();

    $component
        ->fillForm([
            'amount' => '200.000',
            'tenders' => [
                ['method' => TenderMethod::Cash->value, 'amount' => '200.000', 'external_reference' => null],
            ],
        ], 'collectForm')
        ->call('finalize');

    expect(Payment::query()->count())->toBe(1);

    /*
     * A genuine second installment, not a double click: a different amount
     * under the same panel-lifetime key. RecordPaymentAction refuses it as an
     * idempotency conflict, which is right — the operator must reopen the
     * bill. What must not happen is a 500 leaving the 300 unrecorded with no
     * explanation.
     */
    $component
        ->fillForm([
            'amount' => '300.000',
            'tenders' => [
                ['method' => TenderMethod::Cash->value, 'amount' => '300.000', 'external_reference' => null],
            ],
        ], 'collectForm')
        ->call('finalize');

    FilamentNotification::assertNotified(__('collect.reopen_for_next_installment'));

    expect(Payment::query()->count())->toBe(
        1,
        'A conflicting second installment was recorded rather than refused.',
    )->and(ChargeBalance::outstandingFor($charge->getKey())->toDecimal())->toBe('800.000');
});

it('refuses a zero amount as a field error rather than a developer exception', function (string $amount) {
    /*
     * The shape regex accepts 0.000. Without a positivity rule this reached
     * TenderData's constructor, whose guard is an English developer
     * diagnostic — Money's docblock is explicit that a guard reaching a user
     * means the validation rule is missing, not that the wording is wrong.
     */
    $batch = Batch::factory()->create(['price' => '1000.000']);
    $student = Student::factory()->create();

    $component = Livewire::actingAs($this->admin)
        ->test(EnrollAndCollect::class)
        ->fillForm([
            'student_id' => $student->getKey(),
            'batch_id' => $batch->getKey(),
        ])
        ->call('confirm');

    $component
        ->fillForm([
            'amount' => $amount,
            'tenders' => [
                ['method' => TenderMethod::Cash->value, 'amount' => $amount, 'external_reference' => null],
            ],
        ], 'collectForm')
        ->call('finalize')
        ->assertHasFormErrors(['amount'], 'collectForm');

    expect(Payment::query()->count())->toBe(0);
})->with([
    'zero' => ['0.000'],
    'zero, unpadded' => ['0'],
]);
