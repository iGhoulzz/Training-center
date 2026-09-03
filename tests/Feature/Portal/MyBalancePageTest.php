<?php

declare(strict_types=1);

use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Course;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\Student;
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
| My balance — per-enrolment outstanding and a total; gated on
| view_own_balance
|--------------------------------------------------------------------------
|
| Bespoke, single-ability actors — see OverviewPageTest's own header for why
| the seeded `student` role cannot prove one ability gates one thing.
*/

/**
 * @param  array<int, string>  $abilities
 * @return array{0: User, 1: Student}
 */
function balanceActor(array $abilities): array
{
    $user = User::factory()->create(['is_active' => true]);
    $student = Student::factory()->for($user)->create();
    $user->givePermissionTo($abilities);

    return [$user->fresh(), $student];
}

/** An enrolment for $student billed exactly $amount, nothing paid against it. */
function billedEnrollmentFor(Student $student, string $amount): Enrollment
{
    $enrollment = Enrollment::factory()->for($student)->for(Batch::factory()->for(Course::factory()))->create();

    Charge::factory()->create([
        'enrollment_id' => $enrollment->getKey(),
        'list_price' => $amount,
        'amount' => $amount,
    ]);

    return $enrollment;
}

it('renders for a student holding view_own_balance', function () {
    [$user, $student] = balanceActor(['access_student_portal', 'view_own_balance']);
    $enrollment = billedEnrollmentFor($student, '500.000');

    $this->actingAs($user, 'student')->get('/portal/my-balance')
        ->assertSuccessful()
        ->assertSee(__('portal.balance_enrollment_row', ['id' => $enrollment->getKey()]), false)
        ->assertSee(__('portal.amount_lyd', ['amount' => '500.000']), false);
});

it('refuses a student without view_own_balance', function () {
    [$user] = balanceActor(['access_student_portal', 'view_own_student_record', 'view_own_enrollment', 'view_own_certificate']);

    $this->actingAs($user, 'student')->get('/portal/my-balance')->assertForbidden();
});

it('shows an unbilled enrolment as zero owed', function () {
    /*
     * RENAMED. This read "and totals correctly across rows" while asserting
     * only two ROW figures — both satisfied by the row cells alone, with the
     * total never examined. Cross-review proved the gap: hardcoding the page's
     * total to Money::zero() left this and every other test green. The total
     * now has its own test below, and this one is named for what it does.
     */
    [$user, $student] = balanceActor(['access_student_portal', 'view_own_balance']);

    billedEnrollmentFor($student, '300.000');
    // Unbilled — StudentBalanceQuery's RIGHT JOIN keeps it in the summary at
    // zero rather than dropping it, and this page must render that row too.
    Enrollment::factory()->for($student)->for(Batch::factory()->for(Course::factory()))->create();

    $this->actingAs($user, 'student')->get('/portal/my-balance')
        ->assertSuccessful()
        ->assertSee(__('portal.amount_lyd', ['amount' => '0.000']), false)
        ->assertSee(__('portal.amount_lyd', ['amount' => '300.000']), false);
});

it('renders a total that is the sum of the rows, distinguishable from every row', function () {
    /*
     * THE TOTAL, WHICH NOTHING PREVIOUSLY ASSERTED.
     *
     * Two rows with DIFFERENT non-zero amounts, chosen so the sum matches
     * neither of them: 300.000 + 40.000 = 340.000. A total hardcoded to zero,
     * a total that echoed one row, or a missing total all fail here — none of
     * which the previous tests could detect.
     *
     * The expected string is arithmetic done here, not a re-read of the page's
     * own total, so this cannot agree with the bug.
     */
    [$user, $student] = balanceActor(['access_student_portal', 'view_own_balance']);

    billedEnrollmentFor($student, '300.000');
    billedEnrollmentFor($student, '40.000');

    // Rendered HTML only — the figures are also in the Livewire snapshot, so a
    // raw assertSee() would pass against a page that displayed nothing.
    $rendered = renderedWithoutLivewireState(
        $this->actingAs($user, 'student')->get('/portal/my-balance')->assertSuccessful(),
    );

    expect($rendered)
        ->toContain(__('portal.amount_lyd', ['amount' => '300.000']))
        ->toContain(__('portal.amount_lyd', ['amount' => '40.000']))
        ->toContain(__('portal.amount_lyd', ['amount' => '340.000']));
});

it('shows an empty state for a student with no enrolments, rather than erroring', function () {
    [$user] = balanceActor(['access_student_portal', 'view_own_balance']);

    // The @empty branch itself, not merely the zero total — review noted the
    // total alone leaves the empty message unasserted.
    $this->actingAs($user, 'student')->get('/portal/my-balance')
        ->assertSuccessful()
        ->assertSee(__('portal.no_enrollments'), false)
        ->assertSee(__('portal.amount_lyd', ['amount' => '0.000']), false);
});

it('refuses a student who holds view_own_balance but not portal access', function () {
    // Mirrors OverviewPageTest's case; review noted only that file had one.
    [$user] = balanceActor(['view_own_balance']);

    $this->actingAs($user, 'student')->get('/portal/my-balance')->assertForbidden();
});
