<?php

declare(strict_types=1);

use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Course;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\Student;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\LazyLoadingViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
|--------------------------------------------------------------------------
| The non-production lazy-loading guard (P35-T04)
|--------------------------------------------------------------------------
|
| `AppServiceProvider::boot()` calls
| `Model::preventLazyLoading(! $this->app->isProduction())`, so an unexpected
| relationship read throws in development and CI and is left alone in
| production.
|
| ASSERTING THE FLAG ALONE WOULD NOT BE WORTH WRITING. `preventsLazyLoading()`
| returning true says the setter ran; it does not say a violation actually
| throws, and a future change to how Eloquent honours the flag would leave the
| flag test green over a dead guard. So the guard is exercised against a real
| relationship on a real row, with a control alongside it proving the failure
| is caused by the missing eager load and not by anything else in the fixture.
*/

uses(RefreshDatabase::class);

/** One enrolment with a student, batch and course behind it. */
function guardedEnrollment(): Enrollment
{
    return Enrollment::factory()
        ->for(Student::factory())
        ->for(Batch::factory()->for(Course::factory()))
        ->create();
}

it('has the guard enabled in the testing environment', function () {
    expect(Model::preventsLazyLoading())->toBeTrue(
        'The suite is running without the lazy-loading guard, so an N+1 introduced by any '
        .'later task would pass CI silently.',
    );
});

it('throws when a relationship is read on a row from a multi-row result', function () {
    guardedEnrollment();
    guardedEnrollment();

    // Re-fetched deliberately: the instances the factory returns already have
    // their relations set, so reading through those would prove nothing.
    $enrollment = Enrollment::query()->get()->first();

    expect(fn (): ?Student => $enrollment->student)
        ->toThrow(LazyLoadingViolationException::class);
});

it('does not throw when the same relationship is eager loaded', function () {
    /*
     * The control. Without it, the test above would still pass if the fixture
     * were broken in some unrelated way, and the pair would agree with each
     * other rather than with the guard.
     */
    guardedEnrollment();
    guardedEnrollment();

    $enrollment = Enrollment::query()->with('student')->get()->first();

    expect($enrollment->student)->toBeInstanceOf(Student::class);
});

it('does not fire for a model hydrated from a single-row result, which is the guard\'s real boundary', function () {
    /*
     * NOT A WISH. This is what the installed framework does, and it is the
     * single most misleading thing about this guard.
     *
     * `Builder::hydrate():498-501` sets the per-instance flag ONLY when the
     * result carried more than one row:
     *
     *     $model = $instance->newFromBuilder($item);
     *     if (count($items) > 1) {
     *         $model->preventsLazyLoading = Model::preventsLazyLoading();
     *     }
     *
     * So `findOrFail()`, `first()` and `find()` produce a model on which the
     * check at HasAttributes:580 can never fire. Laravel's reasoning is sound —
     * one model read once is not an N+1 — but the consequence is that anyone
     * verifying the guard with `Model::find(1)->relation` will watch it pass and
     * conclude the guard is broken or not installed.
     *
     * It is pinned here so that if a future Laravel drops the row-count
     * condition, this test fails and tells us the guard just got wider, rather
     * than the change landing unnoticed.
     */
    $created = guardedEnrollment();

    $enrollment = Enrollment::query()->findOrFail($created->getKey());

    expect($enrollment->relationLoaded('student'))->toBeFalse()
        ->and($enrollment->student)->toBeInstanceOf(Student::class);
});

it('ties the guard to the production environment rather than enabling it everywhere', function () {
    /*
     * WHY THIS IS A CODE-SHAPE CHECK AND NOT A BEHAVIOURAL ONE, STATED PLAINLY
     * SO NOBODY MISTAKES IT FOR PROOF.
     *
     * The behavioural version would set the environment to production, re-boot
     * AppServiceProvider, and assert the flag went off. That is not safe here:
     * boot() also attaches the Login, Logout and Failed event listeners, and
     * re-running it would leave a second copy of each registered for the rest of
     * the process, double-writing activity rows for every later test in this
     * worker. Trading a real cross-test defect for a stronger assertion about a
     * one-line condition is a bad exchange.
     *
     * So this asserts the shape of the call, over CODE with comments and string
     * literals stripped — DatabaseIsolationTest learned that lesson the hard way
     * when a guard read its own error message as proof it was isolated.
     *
     * What it genuinely protects: someone "fixing" a failing test by changing
     * the argument to a bare `true`, which would enable the guard in production
     * and turn a lazy load into a 500 on a public request.
     */
    $source = (string) file_get_contents(base_path('app/Providers/AppServiceProvider.php'));

    $code = '';

    foreach (token_get_all($source) as $token) {
        if (! is_array($token)) {
            $code .= $token;

            continue;
        }

        $skipped = [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE];

        $code .= in_array($token[0], $skipped, true) ? ' ' : $token[1];
    }

    expect($code)->toMatch('/Model::preventLazyLoading\(\s*!\s*\$this->app->isProduction\(\)\s*\)/')
        ->and($code)->not->toMatch('/Model::preventLazyLoading\(\s*(true|false)\s*\)/');
});
