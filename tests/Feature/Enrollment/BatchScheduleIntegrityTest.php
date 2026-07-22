<?php

declare(strict_types=1);

use App\Domain\Enrollment\Enums\BatchStatus;
use App\Domain\Enrollment\Filament\Resources\BatchResource\Pages\CreateBatch;
use App\Domain\Enrollment\Filament\Resources\BatchResource\Pages\EditBatch;
use App\Domain\Enrollment\Filament\Resources\BatchResource\Pages\ListBatches;
use App\Domain\Enrollment\Filament\Resources\CourseResource\Pages\CreateCourse;
use App\Domain\Enrollment\Filament\Resources\CourseResource\Pages\EditCourse;
use App\Domain\Enrollment\Filament\Resources\CourseResource\Pages\ListCourses;
use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Course;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

/**
 * Schedule integrity, and the failure modes a review found by driving the UI.
 *
 * Each case here was a real defect: a completed batch could be moved to another
 * course, retiring a course made its batches unsaveable, deleting a course with
 * batches produced a raw database error, clearing capacity crashed, and undated
 * batches sorted to the top of a list that exists to answer "what starts next".
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->system = app(SystemRoleWriter::class);

    $this->admin = User::factory()->create(['is_active' => true]);
    $this->system->assignRoles($this->admin, 'admin');
    $this->admin->refresh();
});

/*
|--------------------------------------------------------------------------
| The parent course is immutable after creation
|--------------------------------------------------------------------------
*/

it('refuses to re-parent a batch through a crafted edit payload', function () {
    // Re-parenting rewrites what the batch inherits, and from Tasks 10 and 11
    // it would strand instructor hours and enrolments against a course those
    // people never touched. It was previously possible on a COMPLETED batch.
    $original = Course::factory()->create(['total_hours' => 30]);
    $other = Course::factory()->create(['total_hours' => 10]);

    $batch = Batch::factory()->for($original)->create([
        'status' => BatchStatus::Completed,
        'total_hours' => null,
    ]);

    Livewire::actingAs($this->admin)
        ->test(EditBatch::class, ['record' => $batch->getKey()])
        ->fillForm(['course_id' => $other->getKey()])
        ->call('save');

    $batch->refresh();

    expect($batch->course_id)->toBe($original->getKey())
        // And the inherited figure is unchanged, which is the reason it matters.
        ->and($batch->effective_total_hours)->toBe(30);
});

it('still lets the parent course be chosen when the batch is created', function () {
    $course = Course::factory()->create();

    Livewire::actingAs($this->admin)
        ->test(CreateBatch::class)
        ->fillForm([
            'course_id' => $course->getKey(),
            'code' => 'BATCH-NEW-001',
            'status' => BatchStatus::Planned->value,
            'capacity' => 20,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Batch::where('code', 'BATCH-NEW-001')->first()->course_id)
        ->toBe($course->getKey());
});

it('keeps a retired parent course selectable so its batches stay editable', function () {
    // Retiring a course previously removed it from the options, so the batch's
    // own current value failed validation and an unrelated edit could not save.
    $retired = Course::factory()->create(['is_active' => false]);
    $batch = Batch::factory()->for($retired)->create(['code' => 'OLD-001']);

    Livewire::actingAs($this->admin)
        ->test(EditBatch::class, ['record' => $batch->getKey()])
        ->fillForm(['code' => 'OLD-001-RENAMED'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($batch->fresh()->code)->toBe('OLD-001-RENAMED');
});

it('does not offer a retired course to a brand new batch', function () {
    // The exception above is only for the batch's existing parent. A retired
    // course must not receive new intakes.
    Course::factory()->create(['is_active' => false, 'code' => 'RETIRED-1']);
    $active = Course::factory()->create(['is_active' => true, 'code' => 'ACTIVE-1']);

    $options = Livewire::actingAs($this->admin)
        ->test(CreateBatch::class)
        ->instance()
        ->form
        ->getComponent('course_id')
        ->getOptions();

    expect($options)->toContain('ACTIVE-1')
        ->and($options)->not->toContain('RETIRED-1');
});

/*
|--------------------------------------------------------------------------
| Capacity
|--------------------------------------------------------------------------
*/

it('rejects a blank capacity as a field error rather than a database crash', function () {
    // capacity is NOT NULL. An empty box previously reached MySQL as NULL and
    // surfaced as a raw QueryException.
    $course = Course::factory()->create();

    Livewire::actingAs($this->admin)
        ->test(CreateBatch::class)
        ->fillForm([
            'course_id' => $course->getKey(),
            'code' => 'BATCH-NOCAP',
            'status' => BatchStatus::Planned->value,
            'capacity' => null,
        ])
        ->call('create')
        ->assertHasFormErrors(['capacity']);

    expect(Batch::where('code', 'BATCH-NOCAP')->exists())->toBeFalse();
});

it('accepts zero capacity, the explicit way to say no limit', function () {
    $course = Course::factory()->create();

    Livewire::actingAs($this->admin)
        ->test(CreateBatch::class)
        ->fillForm([
            'course_id' => $course->getKey(),
            'code' => 'BATCH-ZEROCAP',
            'status' => BatchStatus::Planned->value,
            'capacity' => 0,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Batch::where('code', 'BATCH-ZEROCAP')->first()->capacity)->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Schedule ordering
|--------------------------------------------------------------------------
*/

it('lists dated batches before undated ones', function () {
    // MySQL orders NULL first ascending, so a plain defaultSort('start_date')
    // pushed the batch starting next below every batch with no date at all —
    // the opposite of what this list is for.
    $course = Course::factory()->create();

    Batch::factory()->for($course)->create(['code' => 'NO-DATE', 'start_date' => null]);
    Batch::factory()->for($course)->create(['code' => 'LATER', 'start_date' => '2026-09-01']);
    Batch::factory()->for($course)->create(['code' => 'SOONEST', 'start_date' => '2026-03-01']);

    Livewire::actingAs($this->admin)
        ->test(ListBatches::class)
        ->assertCanSeeTableRecords(
            Batch::whereIn('code', ['SOONEST', 'LATER', 'NO-DATE'])
                ->orderByRaw('start_date IS NULL ASC')
                ->orderBy('start_date')
                ->get(),
            inOrder: true,
        );
});

/*
|--------------------------------------------------------------------------
| Deleting a course that is still in use
|--------------------------------------------------------------------------
*/

it('refuses to delete a course that still has batches, readably, from the table', function () {
    $course = Course::factory()->create();
    Batch::factory()->for($course)->create();

    Livewire::actingAs($this->admin)
        ->test(ListCourses::class)
        ->callTableAction('delete', $course)
        ->assertNotified();

    expect(Course::whereKey($course->getKey())->exists())->toBeTrue();
});

it('refuses to delete a course that still has batches, readably, from the edit page', function () {
    $course = Course::factory()->create();
    Batch::factory()->for($course)->create();

    Livewire::actingAs($this->admin)
        ->test(EditCourse::class, ['record' => $course->getKey()])
        ->callAction('delete')
        ->assertNotified();

    expect(Course::whereKey($course->getKey())->exists())->toBeTrue();
});

it('deletes a course that has no batches', function () {
    // The positive control: the refusal above must be about the batches, not a
    // delete that never works.
    $course = Course::factory()->create();

    Livewire::actingAs($this->admin)
        ->test(ListCourses::class)
        ->callTableAction('delete', $course);

    expect(Course::whereKey($course->getKey())->exists())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| The database is the backstop, not the form
|--------------------------------------------------------------------------
*/

it('refuses backwards dates at the database, not only in the form', function () {
    // Form validation does not cover a seeder, a console command or an Action.
    // A backwards batch silently corrupts every schedule built on it.
    $course = Course::factory()->create();

    expect(fn () => Batch::factory()->for($course)->create([
        'start_date' => '2026-12-01',
        'end_date' => '2026-01-01',
    ]))->toThrow(QueryException::class);
});

it('still accepts a batch with one date or no dates', function () {
    // The constraint compares against NULL, which yields NULL rather than
    // false, so a partially scheduled batch must remain valid.
    $course = Course::factory()->create();

    $undated = Batch::factory()->for($course)->create(['start_date' => null, 'end_date' => null]);
    $startOnly = Batch::factory()->for($course)->create(['start_date' => '2026-03-01', 'end_date' => null]);
    $endOnly = Batch::factory()->for($course)->create(['start_date' => null, 'end_date' => '2026-06-01']);

    expect($undated->exists)->toBeTrue()
        ->and($startOnly->exists)->toBeTrue()
        ->and($endOnly->exists)->toBeTrue();
});

it('accepts a batch that starts and ends on the same day', function () {
    // A one-day workshop is a real thing; the constraint is >=, not >.
    $course = Course::factory()->create();

    $sameDay = Batch::factory()->for($course)->create([
        'start_date' => '2026-03-01',
        'end_date' => '2026-03-01',
    ]);

    expect($sameDay->exists)->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| viewAny and view are separate grants
|--------------------------------------------------------------------------
*/

it('separates viewAny from view on courses and batches', function () {
    // A role fixture holds both, so it cannot tell them apart. These actors are
    // built from single permissions: holding the list grant must not confer the
    // record grant, nor the reverse.
    $course = Course::factory()->create();
    $batch = Batch::factory()->for($course)->create();

    $lister = User::factory()->create(['is_active' => true]);
    $lister->givePermissionTo('access_admin_panel', 'view_any_course', 'view_any_batch');

    $reader = User::factory()->create(['is_active' => true]);
    $reader->givePermissionTo('access_admin_panel', 'view_course', 'view_batch');

    expect($lister->fresh()->can('viewAny', Course::class))->toBeTrue()
        ->and($lister->fresh()->can('view', $course))->toBeFalse()
        ->and($lister->fresh()->can('viewAny', Batch::class))->toBeTrue()
        ->and($lister->fresh()->can('view', $batch))->toBeFalse();

    expect($reader->fresh()->can('view', $course))->toBeTrue()
        ->and($reader->fresh()->can('viewAny', Course::class))->toBeFalse()
        ->and($reader->fresh()->can('view', $batch))->toBeTrue()
        ->and($reader->fresh()->can('viewAny', Batch::class))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Course form validation, submitted rather than inspected
|--------------------------------------------------------------------------
*/

it('rejects a course submitted without a code or name', function () {
    Livewire::actingAs($this->admin)
        ->test(CreateCourse::class)
        ->fillForm(['code' => null, 'name_en' => null, 'total_hours' => 10])
        ->call('create')
        ->assertHasFormErrors(['code', 'name_en']);

    expect(Course::count())->toBe(0);
});

it('rejects a duplicate course code on submission', function () {
    Course::factory()->create(['code' => 'ENG-B1']);

    Livewire::actingAs($this->admin)
        ->test(CreateCourse::class)
        ->fillForm(['code' => 'ENG-B1', 'name_en' => 'Duplicate', 'total_hours' => 10])
        ->call('create')
        ->assertHasFormErrors(['code']);

    expect(Course::where('code', 'ENG-B1')->count())->toBe(1);
});

it('rejects total hours beyond the column ceiling', function () {
    // unsignedSmallInteger tops out at 65535; a larger value would truncate
    // silently or raise a raw database error.
    Livewire::actingAs($this->admin)
        ->test(CreateCourse::class)
        ->fillForm(['code' => 'HUGE-1', 'name_en' => 'Huge', 'total_hours' => 70000])
        ->call('create')
        ->assertHasFormErrors(['total_hours']);

    expect(Course::where('code', 'HUGE-1')->exists())->toBeFalse();
});
