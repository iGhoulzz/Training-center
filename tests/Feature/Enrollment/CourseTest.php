<?php

declare(strict_types=1);

use App\Domain\Enrollment\Models\Course;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The course catalogue entry itself.
 *
 * These tests pin the parts the database enforces — the unique code, the
 * is_active default, the price column's precision — because each one is
 * invisible in application code and would otherwise surface only in production.
 */
uses(RefreshDatabase::class);

it('requires a unique course code', function () {
    // The code is what humans mean by a course, on the phone and in brochures,
    // so two courses sharing one is a real-world ambiguity, not merely a broken
    // index.
    Course::factory()->create(['code' => 'ENG-B1']);
    Course::factory()->create(['code' => 'ENG-B1']);
})->throws(UniqueConstraintViolationException::class);

it('defaults is_active to true when the column is not set', function () {
    // Written through the query builder so the factory's explicit value cannot
    // mask a missing database default. A row inserted by an import script or a
    // console command must still land in a known state.
    DB::table('courses')->insert([
        'code' => 'CRS-RAW-0001',
        'name_en' => 'Raw Insert',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $course = Course::firstOrFail();

    expect($course->is_active)->toBeTrue()
        ->and($course->total_hours)->toBe(0);
});

it('scopes to active courses', function () {
    Course::factory()->create();
    Course::factory()->inactive()->create();
    Course::factory()->inactive()->create();

    expect(Course::active()->count())->toBe(1)
        ->and(Course::count())->toBe(3);
});

it('keeps a retired course in the catalogue rather than removing it', function () {
    // Retiring is not deleting: past batches and their enrolment history still
    // point at the course, so the row stays and only leaves the active list.
    $course = Course::factory()->inactive()->create();

    expect(Course::count())->toBe(1)
        ->and(Course::active()->count())->toBe(0)
        ->and($course->is_active)->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| The localized name
|--------------------------------------------------------------------------
*/

it('returns the arabic name when the locale is arabic and a translation exists', function () {
    $course = Course::factory()->translated()->create(['name_en' => 'English B1']);

    App::setLocale('ar');

    expect($course->name())->toBe('دورة اللغة الإنجليزية');
});

it('falls back to english when the arabic name is null', function () {
    // The normal state until phase 4: a course added before anyone translated
    // it must still have a usable name for an Arabic reader, not an empty cell.
    $course = Course::factory()->create([
        'name_en' => 'English B1',
        'name_ar' => null,
    ]);

    App::setLocale('ar');

    expect($course->name())->toBe('English B1');
});

it('falls back to english when the locale is english even if a translation exists', function () {
    // The other half of the rule, and the half a naive `name_ar ?: name_en`
    // would get wrong: a translation being present must not override an English
    // reader's locale.
    $course = Course::factory()->translated()->create(['name_en' => 'English B1']);

    App::setLocale('en');

    expect($course->name())->toBe('English B1');
});

it('falls back to english when the arabic name is an empty string', function () {
    // filled(), not isset(): a form that submits an empty text input stores ''
    // rather than null, and an empty heading is worse than an untranslated one.
    $course = Course::factory()->create([
        'name_en' => 'English B1',
        'name_ar' => '',
    ]);

    App::setLocale('ar');

    expect($course->name())->toBe('English B1');
});

/*
|--------------------------------------------------------------------------
| PHASE DISCIPLINE: the price column exists, holds dirham, and is invisible
|--------------------------------------------------------------------------
|
| default_price is created now so phase 2 never has to ALTER a table holding
| production data. Phase 1 has no financial features, so these tests pin two
| separate things: the column's precision (getting it wrong later means a
| migration on live money data) and its absence from the UI, asserted in
| CourseResourceTest.
*/

it('stores default_price as decimal with three places, not two and not float', function () {
    // The currency is LYD, which subdivides into 1000 dirham per ISO 4217.
    // decimal(12,2) would silently round every dirham-precision amount and
    // break reconciliation; a float would do worse, inexactly.
    $column = collect(Schema::getColumns('courses'))
        ->firstWhere('name', 'default_price');

    expect($column)->not->toBeNull('courses.default_price does not exist.')
        ->and($column['type_name'])->toBe('decimal')
        ->and($column['type'])->toBe('decimal(12,3)');
});

it('round-trips a three-decimal price without losing a dirham', function () {
    // 1250.750 LYD is one thousand two hundred fifty dinars and 750 dirham.
    // Under decimal(12,2) this comes back as 1250.75 — the same number to look
    // at, a different value to reconcile.
    $course = Course::factory()->create(['default_price' => 1250.750]);

    expect((string) $course->fresh()?->default_price)->toBe('1250.750');

    // Asserted at the storage layer too, past the model's decimal:3 cast, so a
    // cast that happened to format correctly could not hide a truncated column.
    expect(DB::table('courses')->where('id', $course->getKey())->value('default_price'))
        ->toBe('1250.750');
});

it('defaults default_price to zero rather than null', function () {
    DB::table('courses')->insert([
        'code' => 'CRS-RAW-0002',
        'name_en' => 'Raw Insert',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect((string) Course::firstOrFail()->default_price)->toBe('0.000');
});
