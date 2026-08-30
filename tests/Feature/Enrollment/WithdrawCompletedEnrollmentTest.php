<?php

declare(strict_types=1);

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
| The branch WithdrawEnrollmentAction:82 has promised since phase 1
|--------------------------------------------------------------------------
|
| `@throws EnrollmentNotWithdrawableException if the enrolment is completed`
| has sat in that Action's docblock since phase 1, unreachable, because nothing
| in the application could produce a completed enrolment until this task. A
| repository-wide search for EnrollmentStatus::Completed in tests/ found
| exactly one hit before this file existed — the crafted-payload test in
| EnrollmentsRelationManagerTest, which asserts a completely different thing
| (that a crafted `status` field on the ENROL form cannot reach the column).
|
| This is that test, written for the first time now that CompleteEnrollmentAction
| makes a genuinely completed row constructible through the application as well
| as through the factory's ->completed() state.
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

it('refuses to withdraw a genuinely completed enrolment', function () {
    $enrollment = Enrollment::factory()->for($this->batch)->completed()->create();
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
