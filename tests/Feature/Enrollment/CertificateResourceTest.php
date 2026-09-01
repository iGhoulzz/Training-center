<?php

declare(strict_types=1);

use App\Domain\Enrollment\Actions\IssueStudentCertificateAction;
use App\Domain\Enrollment\Filament\Resources\StudentCertificateResource;
use App\Domain\Enrollment\Filament\Resources\StudentCertificateResource\Pages\ListStudentCertificates;
use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Course;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\Student;
use App\Domain\Enrollment\Models\StudentCertificate;
use App\Domain\Finance\Models\Charge;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

/**
 * The certificate register as it is actually reachable over HTTP.
 *
 * REVIEW FINDING, AND THE HOUSE STANDARD IS THE OPPOSITE OF WHAT T5 SHIPPED.
 * The resource is 438 lines and had two static assertions against it — nothing
 * mounted a page, nothing rendered the issue modal, nothing exercised the
 * enrolment picker. CourseResourceTest's own docblock states the principle this
 * file follows: "a correct policy nobody consults denies nothing."
 *
 * The specific thing this closes is the one Done-when item with no test at all:
 * issuing for an enrolment that owes money succeeds AND THE FORM DISPLAYS THE
 * FIGURE. The display half runs through
 * `TextEntry::make('outstanding_balance')->state(fn (Get $get) ...)` — an
 * Infolist entry with Get injection inside a Filament\Actions\Action schema. If
 * that combination did not resolve, the issue modal would throw for every user
 * and nothing in the repository would have known.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    $this->admin = User::factory()->create(['is_active' => true]);
    app(SystemRoleWriter::class)->assignRoles($this->admin, 'admin');

    $this->course = Course::factory()->create(['name_en' => 'Welding Level 1', 'total_hours' => 40]);
    $this->batch = Batch::factory()->for($this->course)->active()->create();
    $this->student = Student::factory()->create(['first_name' => 'Amina', 'last_name' => 'Zarrouk']);
});

it('renders the register index for an authorized actor', function () {
    $this->actingAs($this->admin)->get('/admin/student-certificates')->assertSuccessful();
});

it('renders an issued certificate on the view page', function () {
    $enrollment = Enrollment::factory()->for($this->student)->for($this->batch)->completed()->create();

    $certificate = app(IssueStudentCertificateAction::class)->execute($this->admin, $enrollment);

    $this->actingAs($this->admin)
        ->get('/admin/student-certificates/'.$certificate->getKey())
        ->assertSuccessful()
        ->assertSee($certificate->reference_number);
});

it('offers no create route at all — issued rows are never authored through a form', function () {
    // The register is written only by the three Actions. A create page would be
    // a second write path around them.
    $this->actingAs($this->admin)->get('/admin/student-certificates/create')->assertNotFound();

    expect(StudentCertificateResource::canCreate())->toBeFalse();
});

it('mounts the issue action, so its Get-injected balance entry resolves at all', function () {
    /*
     * HALF ONE OF THE DONE-WHEN'S "the form displays the figure".
     *
     * This proves the SCHEMA BUILDS. issueAction()'s form holds
     * `TextEntry::make('outstanding_balance')->state(fn (Get $get) ...)` — an
     * Infolist entry with Get injection inside a Filament\Actions\Action schema.
     * If that combination did not resolve, mounting would throw and the issue
     * modal would be dead for every user. Nothing in T5 exercised it.
     *
     * It deliberately does NOT assert the rendered figure: the modal body is
     * not in this component's markup at mount time, so an assertSee() here
     * passes or fails for reasons that have nothing to do with the balance.
     * The figure itself is half two, below.
     */
    $enrollment = Enrollment::factory()->for($this->student)->for($this->batch)->completed()->create();

    Charge::factory()->for($enrollment)->create(['amount' => '750.000']);

    Livewire::actingAs($this->admin)
        ->test(ListStudentCertificates::class)
        ->mountAction('issue')
        ->assertActionMounted('issue')
        ->setActionData(['enrollment_id' => $enrollment->getKey()])
        ->assertHasNoActionErrors();
});

it('renders the outstanding balance through the mounted form itself', function () {
    /*
     * CROSS-REVIEW FINDING, AND THE OLD VERSION DESERVED IT.
     *
     * This used to call the private outstandingBalanceDisplay() by reflection.
     * That proved the HELPER computes the figure — but if the TextEntry lost its
     * ->state() closure, or were deleted from the schema outright, the helper
     * test and the mount test would both stay green while the form showed the
     * operator nothing. Two tests, neither of which touched the seam between
     * them.
     *
     * This reaches the entry through the real mounted action's schema, so the
     * chain under test is the one the operator gets: mount the action, select an
     * enrolment, and read the state the component itself resolves.
     */
    $enrollment = Enrollment::factory()->for($this->student)->for($this->batch)->completed()->create();

    Charge::factory()->for($enrollment)->create(['list_price' => '750.000']);

    $component = Livewire::actingAs($this->admin)
        ->test(ListStudentCertificates::class)
        ->mountAction('issue')
        ->setActionData(['enrollment_id' => $enrollment->getKey()]);

    // The LIVE schema the component is rendering, not a freshly built one:
    // Action::getSchema() takes the instance, and the component names it.
    $page = $component->instance();
    $entry = $page->getSchema($page->getMountedActionSchemaName())
        ->getComponent('outstanding_balance');

    expect($entry)->not->toBeNull(
        'The issue form has no outstanding_balance entry — the Done-when requires the form to display the figure.',
    );

    // The literal the charge was created with, never a re-read of
    // ChargeQueryService, which would let this agree with whatever the resource
    // happened to compute.
    expect((string) $entry->getState())->toContain('750.000');
});

it('shows no figure in the balance entry before an enrolment is chosen', function () {
    // The modal's opening state. A stray figure here would be the previous
    // selection leaking, and the entry must not throw on a null selection.
    $component = Livewire::actingAs($this->admin)
        ->test(ListStudentCertificates::class)
        ->mountAction('issue');

    // The LIVE schema the component is rendering, not a freshly built one:
    // Action::getSchema() takes the instance, and the component names it.
    $page = $component->instance();
    $entry = $page->getSchema($page->getMountedActionSchemaName())
        ->getComponent('outstanding_balance');

    expect($entry)->not->toBeNull()
        ->and((string) $entry->getState())->not->toContain('750.000');
});

it('issues through the panel for an enrolment that owes money', function () {
    // The Action must have no debt gate, and the PANEL must not add one:
    // enrolling always raises a bill and there is no screen to collect a later
    // instalment, so blocking here would strand every partial payer.
    $enrollment = Enrollment::factory()->for($this->student)->for($this->batch)->completed()->create();

    Charge::factory()->for($enrollment)->create(['amount' => '750.000']);

    Livewire::actingAs($this->admin)
        ->test(ListStudentCertificates::class)
        ->mountAction('issue')
        ->setActionData(['enrollment_id' => $enrollment->getKey()])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect(StudentCertificate::query()->where('enrollment_id', $enrollment->getKey())->count())->toBe(1);
});

it('keeps an enrolment that already holds a valid certificate out of the picker', function () {
    $issued = Enrollment::factory()->for($this->student)->for($this->batch)->completed()->create();
    app(IssueStudentCertificateAction::class)->execute($this->admin, $issued);

    $available = Enrollment::factory()->for($this->batch)->completed()->create();
    $active = Enrollment::factory()->for($this->batch)->create();

    $results = StudentCertificateResource::searchIssuableEnrollments('');

    expect(array_keys($results))
        ->toContain($available->getKey())
        ->not->toContain($issued->getKey(), 'An enrolment holding a valid certificate was offered for issuance.')
        ->not->toContain($active->getKey(), 'An enrolment that is not completed was offered for issuance.');
});

/*
|--------------------------------------------------------------------------
| The refusal paths, as the operator actually meets them
|--------------------------------------------------------------------------
|
| CROSS-REVIEW FINDING. Every typed refusal these Actions raise must be caught by
| the panel action that can trigger it, or it reaches the operator as a 500.
| replaceAction() caught only NoValidCertificateException while the Action also
| raises CertificateAlreadyIssuedException, and the bounded retry's exhaustion
| threw a generic RuntimeException nothing caught anywhere. A catch list is
| exactly the thing a direct Action test cannot check.
|
| WHAT IS NOT TESTED HERE, AND WHY — STATED RATHER THAN QUIETLY OMITTED.
| The stale ROW-action race that CertificateChangedException exists for cannot be
| reproduced through Filament's testing helpers. `callTableAction()` re-evaluates
| the action's ->visible() closure against FRESH state, and both replace and
| revoke are visible only while the record is `valid` — so the helper refuses to
| dispatch on a row that has since become `replaced`, which is precisely the
| situation under test. A real browser has no such protection: its page was
| rendered while the row was still valid, and the button is still sitting there.
|
| An earlier version of this file had two tests that appeared to cover it. They
| failed with "an action with name [replace] is visible ... Failed asserting that
| false is true" — the helper, not the guard. Rewriting them to pass would have
| meant asserting the helper's visibility behaviour and calling it a race test.
| The guard is proved at the Action level instead, in ReplaceCertificateTest and
| RevokeCertificateTest, where disabling it turns both red.
*/

it('refuses a crafted already-issued enrolment through the issue modal, without a 500', function () {
    /*
     * A REACHABLE refusal, unlike the row-action race above. The picker filters
     * issued enrolments out of the list, but the modal accepts whatever
     * enrollment_id it is given — so this is the crafted-payload path, and it is
     * the one that proves refuse() and halt() are wired.
     */
    $enrollment = Enrollment::factory()->for($this->student)->for($this->batch)->completed()->create();

    app(IssueStudentCertificateAction::class)->execute($this->admin, $enrollment);

    Livewire::actingAs($this->admin)
        ->test(ListStudentCertificates::class)
        ->mountAction('issue')
        ->setActionData(['enrollment_id' => $enrollment->getKey()])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect(StudentCertificate::query()->where('enrollment_id', $enrollment->getKey())->count())
        ->toBe(1, 'The refused issuance inserted a second row.');
});

it('refuses a crafted incomplete enrolment through the issue modal, without a 500', function () {
    // The other reachable refusal on the same path: the enrolment is active, so
    // EnrollmentNotCompletedException is what comes back.
    $enrollment = Enrollment::factory()->for($this->student)->for($this->batch)->create();

    Livewire::actingAs($this->admin)
        ->test(ListStudentCertificates::class)
        ->mountAction('issue')
        ->setActionData(['enrollment_id' => $enrollment->getKey()])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect(StudentCertificate::query()->where('enrollment_id', $enrollment->getKey())->count())
        ->toBe(0, 'A certificate was issued against an enrolment that is not completed.');
});
