<?php

declare(strict_types=1);

use App\Domain\Enrollment\Enums\BatchStatus;
use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Course;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A batch: a course actually running.
 *
 * Two things here would otherwise only surface in production — the inheritance
 * of total_hours from the parent course, and the foreign key that refuses to let
 * a course take its batches down with it. Both are tested by observing the
 * behaviour, not by reading the schema back.
 */
uses(RefreshDatabase::class);

it('requires a unique batch code', function () {
    // The code is quoted at the desk and printed on schedules, so two intakes
    // sharing one is a real-world ambiguity, not merely a broken index.
    Batch::factory()->create(['code' => 'ENG-B1-JAN']);
    Batch::factory()->create(['code' => 'ENG-B1-JAN']);
})->throws(UniqueConstraintViolationException::class);

it('defaults status to planned when the column is not set', function () {
    // Written through the query builder so the factory's explicit status cannot
    // mask a missing database default. A row inserted by an import script must
    // still land in a known state.
    $course = Course::factory()->create();

    DB::table('batches')->insert([
        'course_id' => $course->getKey(),
        'code' => 'BTC-RAW-0001',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $batch = Batch::firstOrFail();

    expect($batch->status)->toBe(BatchStatus::Planned)
        ->and($batch->capacity)->toBe(0)
        // Null, not zero. Zero would look like a decision and would stop the
        // batch inheriting its course's hours for good.
        ->and($batch->total_hours)->toBeNull()
        ->and($batch->price)->toBeNull();
});

it('casts status to the enum', function () {
    expect(Batch::factory()->completed()->create()->fresh()?->status)
        ->toBe(BatchStatus::Completed);
});

it('belongs to its course', function () {
    $course = Course::factory()->create(['code' => 'ENG-B1']);
    $batch = Batch::factory()->for($course)->create();

    expect($batch->course->code)->toBe('ENG-B1')
        ->and($course->batches)->toHaveCount(1)
        ->and($course->batches->first()?->getKey())->toBe($batch->getKey());
});

/*
|--------------------------------------------------------------------------
| Inheritance, not copying
|--------------------------------------------------------------------------
|
| The distinction this whole design turns on. A copied value looks identical on
| day one and drifts silently on day two, with nothing to tell you which batches
| went stale. The third test is the one that tells the two apart.
*/

it('inherits total hours from its course when not overridden', function () {
    $course = Course::factory()->create(['total_hours' => 30]);
    $batch = Batch::factory()->for($course)->create(['total_hours' => null]);

    expect($batch->effective_total_hours)->toBe(30)
        // The batch's own column is still null. Reading the effective value
        // must not quietly write the inherited one back.
        ->and($batch->fresh()?->total_hours)->toBeNull();
});

it('uses its own total hours when overridden', function () {
    $course = Course::factory()->create(['total_hours' => 30]);
    $batch = Batch::factory()->for($course)->create(['total_hours' => 45]);

    expect($batch->effective_total_hours)->toBe(45);
});

it('reflects a later change to the course, proving inheritance and not a copy', function () {
    // THE test that distinguishes the two designs. If the course value were
    // copied into the batch at creation, this would still report 30 — and
    // nothing anywhere would say the figure was out of date.
    $course = Course::factory()->create(['total_hours' => 30]);
    $batch = Batch::factory()->for($course)->create(['total_hours' => null]);

    expect($batch->effective_total_hours)->toBe(30);

    $course->update(['total_hours' => 36]);

    expect($batch->fresh()?->effective_total_hours)->toBe(36);
});

it('leaves an overriding batch untouched when the course changes', function () {
    // The other half of the rule: an explicit override is a decision about this
    // intake, and correcting the catalogue must not silently undo it.
    $course = Course::factory()->create(['total_hours' => 30]);
    $batch = Batch::factory()->for($course)->create(['total_hours' => 45]);

    $course->update(['total_hours' => 36]);

    expect($batch->fresh()?->effective_total_hours)->toBe(45);
});

it('inherits zero when the course itself has zero hours', function () {
    // Zero is a real inherited value, not "unset". A ?? chain that treated it
    // as missing would be wrong, and this pins that it does not.
    $course = Course::factory()->create(['total_hours' => 0]);
    $batch = Batch::factory()->for($course)->create(['total_hours' => null]);

    expect($batch->effective_total_hours)->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Status gating
|--------------------------------------------------------------------------
*/

it('reports whether it accepts enrollments', function () {
    expect(Batch::factory()->create(['status' => BatchStatus::Planned])->acceptsEnrollments())->toBeTrue()
        ->and(Batch::factory()->active()->create()->acceptsEnrollments())->toBeTrue()
        ->and(Batch::factory()->completed()->create()->acceptsEnrollments())->toBeFalse()
        ->and(Batch::factory()->cancelled()->create()->acceptsEnrollments())->toBeFalse();
});

it('treats planned and active as open on the enum itself', function () {
    // isOpen() is a whitelist rather than a `!== Completed` test, so a fifth
    // status added later fails closed instead of silently being open.
    expect(BatchStatus::Planned->isOpen())->toBeTrue()
        ->and(BatchStatus::Active->isOpen())->toBeTrue()
        ->and(BatchStatus::Completed->isOpen())->toBeFalse()
        ->and(BatchStatus::Cancelled->isOpen())->toBeFalse();
});

it('scopes to open batches', function () {
    Batch::factory()->create(['status' => BatchStatus::Planned]);
    Batch::factory()->active()->create();
    Batch::factory()->completed()->create();
    Batch::factory()->cancelled()->create();

    expect(Batch::open()->count())->toBe(2)
        ->and(Batch::count())->toBe(4);
});

it('keeps the open scope in step with acceptsEnrollments', function () {
    // A query filter and a PHP predicate are easy to drift apart, and the drift
    // is silent: the list would show one set of batches and the enrolment form
    // would accept another. This asserts they agree row for row.
    foreach (BatchStatus::cases() as $case) {
        Batch::factory()->create(['status' => $case]);
    }

    $scoped = Batch::open()->pluck('id')->sort()->values()->all();
    $predicated = Batch::all()
        ->filter(fn (Batch $batch): bool => $batch->acceptsEnrollments())
        ->pluck('id')->sort()->values()->all();

    expect($scoped)->toBe($predicated)
        ->and($scoped)->toHaveCount(2);
});

/*
|--------------------------------------------------------------------------
| The load-bearing foreign key: a course cannot take its batches down with it
|--------------------------------------------------------------------------
*/

it('refuses to delete a course that has batches', function () {
    // restrictOnDelete, not cascade. A batch carries the centre's record of who
    // was taught what and when; deleting the catalogue entry must be REFUSED
    // loudly rather than silently destroying that history.
    //
    // The refusal lives in the database rather than CoursePolicy because a
    // policy check races — a batch can be created between the check passing and
    // the delete running. A foreign key cannot be raced.
    $course = Course::factory()->create();
    Batch::factory()->for($course)->create();

    $course->delete();
})->throws(QueryException::class);

it('leaves the course and its batches standing after a refused delete', function () {
    // The exception above is only half the guarantee: nothing may be
    // half-removed on the way to it.
    $course = Course::factory()->create();
    $batch = Batch::factory()->for($course)->create();

    try {
        $course->delete();
    } catch (QueryException) {
        // Expected; asserted by its own test above.
    }

    expect(Course::whereKey($course->getKey())->exists())->toBeTrue()
        ->and(Batch::whereKey($batch->getKey())->exists())->toBeTrue()
        ->and($batch->fresh()?->course_id)->toBe($course->getKey());
});

it('allows deleting a course once its batches are gone', function () {
    // The positive control: the constraint refuses a course WITH batches, not
    // every course. Without this the test above would pass on a table nobody
    // could ever delete from.
    $course = Course::factory()->create();
    $batch = Batch::factory()->for($course)->create();

    $batch->delete();
    $course->delete();

    expect(Course::count())->toBe(0)
        ->and(Batch::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| PHASE DISCIPLINE: the price column exists, holds dirham, and is invisible
|--------------------------------------------------------------------------
|
| batches.price is created now so phase 2 never has to ALTER a table holding
| production data. Phase 1 has no financial features, so these pin the column's
| precision; its absence from the UI is asserted in BatchResourceTest.
*/

it('stores price as decimal with three places, not two and not float', function () {
    // The currency is LYD, which subdivides into 1000 dirham per ISO 4217.
    // decimal(12,2) would silently round every dirham-precision amount and
    // break reconciliation; a float would do worse, inexactly.
    $column = collect(Schema::getColumns('batches'))
        ->firstWhere('name', 'price');

    expect($column)->not->toBeNull('batches.price does not exist.')
        ->and($column['type_name'])->toBe('decimal')
        ->and($column['type'])->toBe('decimal(12,3)')
        // Nullable, because null means inherit from the course — exactly like
        // total_hours. A NOT NULL default of 0 would be a real price of zero.
        ->and($column['nullable'])->toBeTrue();
});

it('round-trips a three-decimal price without losing a dirham', function () {
    // 1250.750 LYD is one thousand two hundred fifty dinars and 750 dirham.
    // Under decimal(12,2) this comes back as 1250.75 — the same number to look
    // at, a different value to reconcile.
    $batch = Batch::factory()->create(['price' => 1250.750]);

    expect((string) $batch->fresh()?->price)->toBe('1250.750');

    // Asserted at the storage layer too, past the model's decimal:3 cast, so a
    // cast that happened to format correctly could not hide a truncated column.
    expect(DB::table('batches')->where('id', $batch->getKey())->value('price'))
        ->toBe('1250.750');
});
