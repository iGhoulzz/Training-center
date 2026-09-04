<?php

declare(strict_types=1);

use App\Domain\Enrollment\Enums\StudentStatus;
use App\Domain\Enrollment\Models\Student;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

/*
|--------------------------------------------------------------------------
| Overview — name, student code, status; gated on view_own_student_record
|--------------------------------------------------------------------------
|
| BESPOKE, SINGLE-ABILITY ACTORS, NOT THE SEEDED student ROLE.
|
| The seeded `student` role holds all five phase 3 abilities at once
| (RolePermissionSeeder), which would hide a page that checked the wrong
| ability — every case would still pass. Every actor built here is a fresh
| user granted exactly the permissions the test names, following
| CourseResourceTest's direct givePermissionTo() pattern, so a mistyped
| ability name or a refusal that actually came from canAccessPanel() (missing
| access_student_portal) cannot be confused with the one under test.
*/

/**
 * A portal-eligible user with a live student record, holding exactly the
 * abilities named.
 *
 * @param  array<int, string>  $abilities
 * @return array{0: User, 1: Student}
 */
function overviewActor(array $abilities, array $studentAttributes = []): array
{
    $user = User::factory()->create(['is_active' => true]);
    $student = Student::factory()->for($user)->create($studentAttributes);
    $user->givePermissionTo($abilities);

    return [$user->fresh(), $student];
}

it('renders for a student holding view_own_student_record', function () {
    [$user, $student] = overviewActor(
        ['access_student_portal', 'view_own_student_record'],
        ['first_name' => 'Amina', 'last_name' => 'Zarrouk', 'student_code' => 'STU-0001', 'status' => StudentStatus::Active],
    );

    $response = $this->actingAs($user, 'student')->get('/portal/overview');

    $response->assertSuccessful()
        ->assertSee('Amina Zarrouk', false)
        ->assertSee('STU-0001', false)
        ->assertSee($student->status->label(), false);
});

it('refuses a student without view_own_student_record', function () {
    // Holds portal access and every OTHER phase 3 ability, but not this one —
    // proves the refusal is scoped to this specific ability, not to portal
    // access in general or to holding no abilities at all.
    [$user] = overviewActor(['access_student_portal', 'view_own_enrollment', 'view_own_balance', 'view_own_certificate']);

    $this->actingAs($user, 'student')->get('/portal/overview')->assertForbidden();
});

it('refuses a user with the ability but no portal access', function () {
    // The ability alone is not enough either — canAccessPanel() requires
    // access_student_portal first. A refusal here for the wrong reason would
    // still look like a pass.
    [$user] = overviewActor(['view_own_student_record']);

    $this->actingAs($user, 'student')->get('/portal/overview')->assertForbidden();
});

it('shows the withdrawn shape of nothing beyond name, code and status', function () {
    // Not a leak test (PortalRowIsolationTest owns that claim) — this pins
    // that the page's own response does not casually echo the rest of the
    // student record it has no business showing.
    [$user, $student] = overviewActor(
        ['access_student_portal', 'view_own_student_record'],
        ['national_id' => 'NID-SECRET-77', 'phone' => '0912345678'],
    );

    $this->actingAs($user, 'student')->get('/portal/overview')
        ->assertSuccessful()
        ->assertDontSee('NID-SECRET-77', false)
        ->assertDontSee('0912345678', false)
        ->assertSee($student->student_code, false);
});
