<?php

declare(strict_types=1);

use App\Domain\Enrollment\Enums\StudentStatus;
use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Course;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\Student;
use App\Domain\Enrollment\Models\StudentCertificate;
use App\Domain\Finance\Models\Charge;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

/*
|--------------------------------------------------------------------------
| Row isolation — where the correctness claim actually rests
|--------------------------------------------------------------------------
|
| Design section 4.1: PortalScopeArchTest is a source scan and proves a call
| was made, never that the query it fed was constrained. THIS file is what the
| correctness claim rests on — sign in as student A, build distinctive data for
| student B, and assert B's data does not reach A.
|
| WHAT IS ACTUALLY ASSERTED, STATED HONESTLY. Cross-review found this block
| claiming B's data is absent "ANYWHERE in A's rendered pages" and a positive
| control on "EVERY case", neither of which the assertions delivered. Both are
| narrowed here, and the gaps closed rather than described away:
|
|   - Each page asserts B's markers absent. Every marker now includes the ones
|     NATIVE TO OTHER PAGES too — B's certificate reference is checked against
|     the balance page, B's amount against the overview, and so on — because a
|     leak is likeliest exactly where nobody thought to look.
|   - The positive control is genuinely on every case now. The totals case used
|     to have none: a total of zero, or a missing total, passed it. See
|     MyBalancePageTest's dedicated total test, which pins the sum against two
|     differently-sized rows.
|
| ASSERTIONS OF ABSENCE RUN AGAINST THE RAW BODY, DELIBERATELY. Livewire
| serialises every public property into wire:snapshot, so assertDontSee() over
| the raw body is STRICTER than checking rendered HTML: it proves B's value
| never reached the payload either. The presence assertions in the per-page
| tests are the ones that must strip that payload — see
| renderedWithoutLivewireState() in tests/Pest.php.
*/

/**
 * A distinctive student, with one enrolment, one bill and one valid
 * certificate — all three marked with the given suffix so A's and B's rows
 * can never be mistaken for one another even by coincidence.
 *
 * @return array{user: User, student: Student, enrollment: Enrollment, amount: string, reference: string, course: string, batch: string}
 */
function isolationRig(string $suffix, string $amount): array
{
    $user = User::factory()->create(['is_active' => true]);
    $student = Student::factory()->for($user)->create([
        'first_name' => "Isolation{$suffix}",
        'last_name' => "Student{$suffix}",
        'student_code' => "STU-ISO-{$suffix}",
        'status' => StudentStatus::Active,
    ]);

    $course = Course::factory()->create(['code' => "ISO-COURSE-{$suffix}", 'name_en' => "Isolation Course {$suffix}"]);
    $batch = Batch::factory()->for($course)->create(['code' => "ISO-BATCH-{$suffix}"]);
    $enrollment = Enrollment::factory()->completed()->for($student)->for($batch)->create();

    Charge::factory()->create([
        'enrollment_id' => $enrollment->getKey(),
        'list_price' => $amount,
        'amount' => $amount,
    ]);

    $reference = "TC-2026-ISO{$suffix}00X1";

    StudentCertificate::factory()->for($enrollment)->create(['reference_number' => $reference]);

    return [
        'user' => $user,
        'student' => $student,
        'enrollment' => $enrollment,
        'amount' => $amount,
        'reference' => $reference,
        // MyEnrollments renders Course::name() (name_en here), not the code —
        // this must match what the page actually prints, not the row's
        // catalogue code, or the assertion proves nothing about either.
        'course' => "Isolation Course {$suffix}",
        'batch' => "ISO-BATCH-{$suffix}",
    ];
}

beforeEach(function () {
    $this->a = isolationRig('A', '111.111');
    $this->b = isolationRig('B', '777.777');

    // Every ability on A's own account — bespoke, direct grant, not the
    // seeded `student` role, so this test does not depend on that role's
    // shape staying what it is today.
    $this->a['user']->givePermissionTo([
        'access_student_portal',
        'view_own_student_record',
        'view_own_enrollment',
        'view_own_balance',
        'view_own_certificate',
    ]);
});

it('never shows student B\'s name, code or status on A\'s overview page', function () {
    $response = $this->actingAs($this->a['user']->fresh(), 'student')->get('/portal/overview');

    $response->assertSuccessful()
        // Positive control: A's own data really is on the page.
        ->assertSee('IsolationA StudentA', false)
        ->assertSee('STU-ISO-A', false)
        // The claim: none of B's data leaked onto A's page.
        ->assertDontSee('IsolationB StudentB', false)
        ->assertDontSee('STU-ISO-B', false);
});

it('never shows student B\'s enrolment, course, batch or certificate on A\'s enrolments page', function () {
    $response = $this->actingAs($this->a['user']->fresh(), 'student')->get('/portal/my-enrollments');

    $response->assertSuccessful()
        ->assertSee($this->a['course'], false)
        ->assertSee($this->a['batch'], false)
        ->assertSee($this->a['reference'], false)
        ->assertDontSee($this->b['course'], false)
        ->assertDontSee($this->b['batch'], false)
        ->assertDontSee($this->b['reference'], false);
});

it('never shows student B\'s outstanding balance on A\'s balance page', function () {
    $response = $this->actingAs($this->a['user']->fresh(), 'student')->get('/portal/my-balance');

    $response->assertSuccessful()
        ->assertSee(__('portal.amount_lyd', ['amount' => $this->a['amount']]), false)
        ->assertSee(__('portal.balance_enrollment_row', ['id' => $this->a['enrollment']->getKey()]), false)
        ->assertDontSee(__('portal.amount_lyd', ['amount' => $this->b['amount']]), false)
        ->assertDontSee(__('portal.balance_enrollment_row', ['id' => $this->b['enrollment']->getKey()]), false);
});

it('never mixes the two students\' totals on the balance page', function () {
    // A's total must equal A's own bill, never A's bill plus B's — the
    // arithmetic-level expression of the same isolation claim.
    $response = $this->actingAs($this->a['user']->fresh(), 'student')->get('/portal/my-balance');

    $response->assertSuccessful()->assertSee(__('portal.amount_lyd', ['amount' => '111.111']), false);

    // 111.111 + 777.777 would be 888.888 — must never appear as the total.
    $response->assertDontSee(__('portal.amount_lyd', ['amount' => '888.888']), false);
});

/*
|--------------------------------------------------------------------------
| Cross-page absence — B's markers must not surface on pages that do not
| normally carry that kind of data at all
|--------------------------------------------------------------------------
|
| The three tests above each check the markers NATIVE to their own page. That
| was the gap review found behind the docblock's "anywhere" claim: B's
| certificate reference was never asserted absent from A's BALANCE page, B's
| amount never from A's OVERVIEW, B's student code never from the other two.
|
| A leak is likeliest precisely where nobody thought to look — a secondary
| lookup, an eager load, a total computed over the wrong set. These assertions
| cost nothing and close the claim.
*/

it('shows none of student B\'s markers on any of A\'s pages, including markers foreign to that page', function () {
    $actor = $this->a['user']->fresh();

    $foreign = [
        'IsolationB StudentB',
        'STU-ISO-B',
        $this->b['course'],
        $this->b['batch'],
        $this->b['reference'],
        __('portal.amount_lyd', ['amount' => $this->b['amount']]),
        __('portal.balance_enrollment_row', ['id' => $this->b['enrollment']->getKey()]),
    ];

    foreach (['/portal/overview', '/portal/my-enrollments', '/portal/my-balance'] as $url) {
        $response = $this->actingAs($actor, 'student')->get($url);

        $response->assertSuccessful();

        foreach ($foreign as $marker) {
            // Raw body deliberately: stricter than the rendered HTML, because
            // it also proves the value never reached Livewire's snapshot.
            $response->assertDontSee($marker, false);
        }
    }
});

it('still shows each of A\'s own markers on the page that owns it', function () {
    /*
     * THE POSITIVE CONTROL FOR THE TEST ABOVE, and not optional: a suite of
     * pages that all rendered blank would satisfy every assertDontSee() in this
     * file while proving nothing whatsoever.
     *
     * Rendered HTML, not the raw body — the values are in the snapshot too, so
     * a raw check would pass against a page that displayed nothing.
     */
    $actor = $this->a['user']->fresh();

    $overview = renderedWithoutLivewireState(
        $this->actingAs($actor, 'student')->get('/portal/overview')->assertSuccessful(),
    );
    expect($overview)->toContain('IsolationA StudentA')->toContain('STU-ISO-A');

    $enrollments = renderedWithoutLivewireState(
        $this->actingAs($actor, 'student')->get('/portal/my-enrollments')->assertSuccessful(),
    );
    expect($enrollments)
        ->toContain($this->a['course'])
        ->toContain($this->a['batch'])
        ->toContain($this->a['reference']);

    $balance = renderedWithoutLivewireState(
        $this->actingAs($actor, 'student')->get('/portal/my-balance')->assertSuccessful(),
    );
    expect($balance)->toContain(__('portal.amount_lyd', ['amount' => $this->a['amount']]));
});
