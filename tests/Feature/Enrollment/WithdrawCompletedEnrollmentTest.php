<?php

declare(strict_types=1);

use App\Domain\Enrollment\Actions\CompleteEnrollmentAction;
use App\Domain\Enrollment\Actions\WithdrawEnrollmentAction;
use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Enrollment\Exceptions\EnrollmentNotWithdrawableException;
use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Course;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Withdrawing a completed enrolment, against the REAL completion path
|--------------------------------------------------------------------------
|
| WHAT THIS FILE IS NOT. An earlier version of this header claimed the
| withdraw-completed branch had never been tested, and that this file was that
| test "written for the first time". Both were false, and review caught it:
| `EnrollmentTest.php:296` — "refuses to withdraw a completed enrollment" — has
| covered the branch since phase 1.
|
| The mistake is worth recording because it is one this project has a name for.
| The plan reasoned from `grep "EnrollmentStatus::Completed" tests/`, which
| returns exactly one hit, and concluded the branch was uncovered. The
| pre-existing test builds its row with the factory's `->completed()` state and
| never names the enum, so the grep could not see it. A SOURCE SCAN CANNOT PROVE
| AN ABSENCE — and that sentence is already in this repository's own notes.
|
| WHAT THIS FILE IS FOR. Design §10 asked for something the phase-1 test cannot
| give: the branch "re-verified against the real path rather than trusted". Until
| P3-T03 there was no real path — `->completed()` wrote the column directly
| because nothing else could. Now `CompleteEnrollmentAction` exists, so a
| genuinely completed enrolment can be produced the way the application produces
| one: under the batch → enrolment lock, with a server-set `completed_at` and an
| activity entry behind it.
|
| That distinction is the whole point. A fixture that sets a column proves the
| refusal reads `status`; a row completed through the Action proves the refusal
| holds against what the system actually creates.
*/

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    $this->system = app(SystemRoleWriter::class);

    $this->admin = User::factory()->create(['is_active' => true]);
    $this->system->assignRoles($this->admin, 'admin');
    $this->admin->refresh();

    $this->course = Course::factory()->create(['total_hours' => 30]);
    $this->batch = Batch::factory()->for($this->course)->active()->create(['capacity' => 5]);

    $this->withdraw = app(WithdrawEnrollmentAction::class);
});

it('refuses to withdraw an enrolment completed through CompleteEnrollmentAction', function () {
    /*
     * THE REAL PATH, not the factory state. This is the assertion design §10
     * asked for and the phase-1 test cannot make: the row is completed by the
     * application, under its own lock, so `completed_at` is the server clock's
     * and an activity entry stands behind it.
     */
    $enrollment = Enrollment::factory()->for($this->batch)->create();

    app(CompleteEnrollmentAction::class)->execute($this->admin, $enrollment);

    $enrollment = $enrollment->fresh();

    expect($enrollment->status)->toBe(EnrollmentStatus::Completed)
        ->and($enrollment->completed_at)->not->toBeNull();

    $completedAt = $enrollment->completed_at;

    expect(fn () => $this->withdraw->execute($this->admin, $enrollment))
        ->toThrow(EnrollmentNotWithdrawableException::class);

    // The refusal must not have half-happened: status and completed_at both
    // stand exactly as they did before the call.
    $fresh = $enrollment->fresh();
    expect($fresh->status)->toBe(EnrollmentStatus::Completed)
        ->and($fresh->completed_at?->toDateTimeString())->toBe($completedAt->toDateTimeString());
});

it('carries the completed enrolment\'s id on the exception, like the withdrawn-batch case does', function () {
    // The FIXTURE is right here: this asserts what the exception carries, not
    // how the row came to be completed. The test above is the one that owns the
    // real-path claim.
    $enrollment = Enrollment::factory()->for($this->batch)->completed()->create();

    try {
        $this->withdraw->execute($this->admin, $enrollment);
        $thrown = null;
    } catch (EnrollmentNotWithdrawableException $exception) {
        $thrown = $exception;
    }

    expect($thrown)->not->toBeNull()
        ->and($thrown->enrollmentId)->toBe((int) $enrollment->getKey());
});
