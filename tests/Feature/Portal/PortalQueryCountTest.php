<?php

declare(strict_types=1);

use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Course;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\Student;
use App\Domain\Enrollment\Models\StudentCertificate;
use App\Domain\Finance\Models\Charge;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

/*
|--------------------------------------------------------------------------
| No N+1 — a fixed number of queries for one enrolment and for twenty
|--------------------------------------------------------------------------
|
| Design section 4.2 names the balance page; the plan is explicit the
| enrolments page carries the identical risk twice over — its certificate
| lookup AND its course/batch columns are each a per-row temptation. "The
| first draft asserted this for the balance page alone, which would have let
| the N+1 reappear one page over." Both are proven here, over real HTTP
| requests through the real Filament page, exactly as a browser would drive
| them — not by calling an internal method directly, which would prove the
| service has no N+1 without proving the PAGE built on top of it does not
| reintroduce one in its own mount().
*/

/**
 * @param  array<int, string>  $abilities
 * @return array{0: User, 1: Student}
 */
function queryCountActor(array $abilities): array
{
    $user = User::factory()->create(['is_active' => true]);
    $student = Student::factory()->for($user)->create();
    $user->givePermissionTo($abilities);

    return [$user->fresh(), $student];
}

/** One enrolment for $student, completed, with a valid certificate. */
function enrolledAndCertified(Student $student): Enrollment
{
    $enrollment = Enrollment::factory()->completed()->for($student)
        ->for(Batch::factory()->for(Course::factory()))
        ->create();

    StudentCertificate::factory()->for($enrollment)->create();

    return $enrollment;
}

/** One billed enrolment for $student, nothing paid against it. */
function enrolledAndBilled(Student $student): Enrollment
{
    $enrollment = Enrollment::factory()->for($student)
        ->for(Batch::factory()->for(Course::factory()))
        ->create();

    Charge::factory()->create([
        'enrollment_id' => $enrollment->getKey(),
        'list_price' => '100.000',
        'amount' => '100.000',
    ]);

    return $enrollment;
}

it('issues the same number of queries for the enrolments page whether the student holds one enrolment or twenty', function () {
    [$soloUser, $soloStudent] = queryCountActor(['access_student_portal', 'view_own_enrollment', 'view_own_certificate']);
    enrolledAndCertified($soloStudent);

    [$busyUser, $busyStudent] = queryCountActor(['access_student_portal', 'view_own_enrollment', 'view_own_certificate']);

    foreach (range(1, 20) as $i) {
        // A mix, so the certificate lookup and the course/batch eager load are
        // both exercised at volume rather than only the cheapest shape.
        $i % 2 === 0 ? enrolledAndCertified($busyStudent) : Enrollment::factory()->for($busyStudent)
            ->for(Batch::factory()->for(Course::factory()))
            ->create();
    }

    // Spatie caches the whole permission-to-role map on its first read after
    // RolePermissionSeeder flushed it, in a store shared by every actor in
    // this process. Priming it here, before the listener attaches, keeps
    // that one-time cache-miss cost out of BOTH measured requests rather
    // than landing on whichever of them happens to run first — which would
    // otherwise fail this test for a reason that has nothing to do with an
    // N+1 in either page.
    $soloUser->can('view_own_enrollment');

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $this->actingAs($soloUser, 'student')->get('/portal/my-enrollments')->assertSuccessful();
    $queriesForOne = $queries;

    $queries = 0;
    $this->actingAs($busyUser, 'student')->get('/portal/my-enrollments')->assertSuccessful();
    $queriesForTwenty = $queries;

    expect($queriesForOne)->toBeGreaterThan(0)
        ->and($queriesForOne)->toBe(
            $queriesForTwenty,
            "One enrolment cost {$queriesForOne} queries; twenty cost {$queriesForTwenty}. ".
            'MyEnrollments must issue a fixed number of statements regardless of enrolment count.',
        );
});

it('issues the same number of queries for the balance page whether the student holds one enrolment or twenty', function () {
    [$soloUser, $soloStudent] = queryCountActor(['access_student_portal', 'view_own_balance']);
    enrolledAndBilled($soloStudent);

    [$busyUser, $busyStudent] = queryCountActor(['access_student_portal', 'view_own_balance']);

    foreach (range(1, 20) as $i) {
        $i % 2 === 0 ? enrolledAndBilled($busyStudent) : Enrollment::factory()->for($busyStudent)
            ->for(Batch::factory()->for(Course::factory()))
            ->create();
    }

    // See the enrolments test above for why this primes Spatie's shared
    // permission cache before the listener attaches.
    $soloUser->can('view_own_balance');

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $this->actingAs($soloUser, 'student')->get('/portal/my-balance')->assertSuccessful();
    $queriesForOne = $queries;

    $queries = 0;
    $this->actingAs($busyUser, 'student')->get('/portal/my-balance')->assertSuccessful();
    $queriesForTwenty = $queries;

    expect($queriesForOne)->toBeGreaterThan(0)
        ->and($queriesForOne)->toBe(
            $queriesForTwenty,
            "One enrolment cost {$queriesForOne} queries; twenty cost {$queriesForTwenty}. ".
            'MyBalance must issue a fixed number of statements regardless of enrolment count.',
        );
});
