<?php

declare(strict_types=1);

use App\Domain\Enrollment\Enums\BatchStatus;
use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Course;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Queries\NextUpcomingBatch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('selects today and the lowest id on a tie and omits inactive courses', function () {
    $this->travelTo(now()->setDateTime(2026, 10, 9, 22, 30));
    $course = Course::factory()->create(['is_active' => true, 'total_hours' => 40, 'default_price' => '123.456']);
    Batch::factory()->for($course)->create(['start_date' => '2026-10-09']);
    // An Active batch with a future start IS an intake since P4-T42 (see the tests below), so it
    // no longer belongs among the statuses that never count.
    foreach ([BatchStatus::Cancelled, BatchStatus::Completed] as $status) {
        Batch::factory()->for($course)->create(['start_date' => '2026-10-10', 'status' => $status]);
    }
    $first = Batch::factory()->for($course)->create(['start_date' => '2026-10-10', 'total_hours' => null, 'price' => null]);
    Batch::factory()->for($course)->create(['start_date' => '2026-10-10', 'price' => '50.000']);
    Batch::factory()->for($course)->create(['start_date' => '2026-10-11']);
    $empty = Course::factory()->create(['is_active' => true]);
    $inactive = Course::factory()->create(['is_active' => false]);
    Batch::factory()->for($inactive)->create(['start_date' => '2026-10-10']);

    $cards = app(NextUpcomingBatch::class)->forCourses();

    expect(array_keys($cards))->toBe([$course->id, $empty->id])
        ->and($cards[$course->id]['batch_id'])->toBe($first->id)
        ->and($cards[$course->id]['batch_code'])->toBe($first->code)
        ->and($cards[$course->id]['start_date'])->toBe('2026-10-10')
        ->and($cards[$course->id]['price']->toDecimal())->toBe('123.456')
        ->and($cards[$course->id]['hours'])->toBe(40)
        ->and($cards[$empty->id])->toBeNull();
});

it('uses the centre day on both sides of UTC midnight and inherits live values', function () {
    $course = Course::factory()->create(['is_active' => true, 'default_price' => '77.777', 'total_hours' => 35]);
    Batch::factory()->for($course)->create(['start_date' => '2026-10-09']);
    $batch = Batch::factory()->for($course)->create(['start_date' => '2026-10-10', 'price' => '0.000', 'total_hours' => 12]);
    foreach (['2026-10-09 23:59:59', '2026-10-10 00:00:01'] as $instant) {
        $this->travelTo(CarbonImmutable::parse($instant, 'UTC'));
        $card = app(NextUpcomingBatch::class)->forCourses()[$course->id];
        expect($card['batch_id'])->toBe($batch->id)
            ->and($card['price']->toDecimal())->toBe('0.000')
            ->and($card['hours'])->toBe(12);
    }
    $batch->update(['price' => null, 'total_hours' => null]);
    $course->update(['default_price' => '88.888', 'total_hours' => 42]);
    $card = app(NextUpcomingBatch::class)->forCourses()[$course->id];
    expect($card['price']->toDecimal())->toBe('88.888')->and($card['hours'])->toBe(42);
});

it('loads one or twenty course cards in one statement', function () {
    $this->travelTo(now()->setDateTime(2026, 10, 10, 10, 0));
    $create = function (): void {
        Batch::factory()->for(Course::factory()->state(['is_active' => true]))
            ->create(['start_date' => '2026-10-10', 'price' => null, 'total_hours' => null]);
    };
    $create();
    $counts = [];
    foreach ([1, 20] as $size) {
        if ($size === 20) {
            for ($i = 1; $i < 20; $i++) {
                $create();
            }
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        expect(app(NextUpcomingBatch::class)->forCourses())->toHaveCount($size);
        $counts[] = count(DB::getQueryLog());
        DB::disableQueryLog();
    }
    expect($counts)->toBe([1, 1]);
});

/*
|--------------------------------------------------------------------------
| An Active batch is an intake, and a batch in session is reported (P4-T42)
|--------------------------------------------------------------------------
|
| Today is 2026-10-12 (centre calendar) in every test below, and every date is a
| literal, so each expected value is written out by hand.
*/

/**
 * One active course with the given batches, read back through the query.
 *
 * @param  list<array<string, mixed>>  $batches  attributes for Batch::factory(), each with a 'state' key naming the factory state
 * @return array{intake: array<string, mixed>|null, in_session: array{start_date: string, end_date: string|null}|null}
 */
function nextUpcomingCardFor(array $batches): array
{
    $course = Course::factory()->create(['is_active' => true, 'default_price' => '10.000', 'total_hours' => 20]);

    foreach ($batches as $attributes) {
        $state = $attributes['state'] ?? 'planned';
        unset($attributes['state']);
        $factory = Batch::factory()->for($course);
        // Planned is the factory's default and has no state of its own.
        ($state === 'planned' ? $factory : $factory->{$state}())->create($attributes);
    }

    return app(NextUpcomingBatch::class)->forCoursesWithSessions()[$course->id];
}

it('treats an Active batch starting today or later as an intake, competing with Planned ones by start date', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 12:00:00', 'UTC'));

    $course = Course::factory()->create(['is_active' => true]);
    Batch::factory()->for($course)->create(['start_date' => '2026-10-30', 'price' => '300.000']);
    $active = Batch::factory()->for($course)->active()->create(['start_date' => '2026-10-20', 'price' => '200.000']);
    Batch::factory()->for($course)->create(['start_date' => '2026-11-15', 'price' => '400.000']);

    $card = app(NextUpcomingBatch::class)->forCourses()[$course->id];

    expect($card['batch_id'])->toBe($active->id)
        ->and($card['start_date'])->toBe('2026-10-20')
        ->and($card['price']->toDecimal())->toBe('200.000');

    // A Planned batch that starts earlier beats the Active one; on the same day the lowest id wins.
    $earlier = Batch::factory()->for($course)->create(['start_date' => '2026-10-15']);
    expect(app(NextUpcomingBatch::class)->forCourses()[$course->id]['batch_id'])->toBe($earlier->id);

    $sameDay = Course::factory()->create(['is_active' => true]);
    $lowest = Batch::factory()->for($sameDay)->active()->create(['start_date' => '2026-10-20']);
    Batch::factory()->for($sameDay)->create(['start_date' => '2026-10-20']);
    expect(app(NextUpcomingBatch::class)->forCourses()[$sameDay->id]['batch_id'])->toBe($lowest->id);
});

it('reports the Active batch that started in the past and has not ended as in session, with its dates', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 12:00:00', 'UTC'));

    $withEnd = nextUpcomingCardFor([['state' => 'active', 'start_date' => '2026-09-01', 'end_date' => '2026-12-15']]);
    $withoutEnd = nextUpcomingCardFor([['state' => 'active', 'start_date' => '2026-09-05', 'end_date' => null]]);
    // The end date is inclusive: a batch whose last day is today is still running.
    $lastDay = nextUpcomingCardFor([['state' => 'active', 'start_date' => '2026-09-05', 'end_date' => '2026-10-12']]);

    expect($withEnd['intake'])->toBeNull()
        ->and($withEnd['in_session'])->toBe(['start_date' => '2026-09-01', 'end_date' => '2026-12-15'])
        ->and($withoutEnd['in_session'])->toBe(['start_date' => '2026-09-05', 'end_date' => null])
        ->and($lastDay['in_session'])->toBe(['start_date' => '2026-09-05', 'end_date' => '2026-10-12']);
});

it('does not report a batch in session once its end date has passed, or a batch that is not Active', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 12:00:00', 'UTC'));

    foreach ([
        'ended yesterday' => [['state' => 'active', 'start_date' => '2026-08-01', 'end_date' => '2026-10-11']],
        'planned and past' => [['state' => 'planned', 'start_date' => '2026-09-01', 'end_date' => '2026-12-15']],
        'completed' => [['state' => 'completed', 'start_date' => '2026-09-01', 'end_date' => '2026-12-15']],
        'cancelled' => [['state' => 'cancelled', 'start_date' => '2026-09-01', 'end_date' => '2026-12-15']],
        // It starts today, so it is an intake and not a batch in session.
        'active, starting today' => [['state' => 'active', 'start_date' => '2026-10-12', 'end_date' => '2026-12-15']],
    ] as $label => $batches) {
        $card = nextUpcomingCardFor($batches);

        expect($card['in_session'])->toBeNull($label);
    }
});

it('reports the batch that started most recently when several are in session, and both an intake and a session when a course has both', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 12:00:00', 'UTC'));

    $several = nextUpcomingCardFor([
        ['state' => 'active', 'start_date' => '2026-08-01', 'end_date' => '2026-12-01'],
        ['state' => 'active', 'start_date' => '2026-09-20', 'end_date' => '2027-01-01'],
        ['state' => 'active', 'start_date' => '2026-09-10', 'end_date' => null],
    ]);
    $both = nextUpcomingCardFor([
        ['state' => 'active', 'start_date' => '2026-09-01', 'end_date' => '2026-12-15'],
        ['state' => 'planned', 'start_date' => '2026-11-02', 'end_date' => '2027-02-01', 'price' => '321.000'],
    ]);

    expect($several['in_session'])->toBe(['start_date' => '2026-09-20', 'end_date' => '2027-01-01'])
        ->and($both['intake']['start_date'])->toBe('2026-11-02')
        ->and($both['intake']['price']->toDecimal())->toBe('321.000')
        ->and($both['in_session'])->toBe(['start_date' => '2026-09-01', 'end_date' => '2026-12-15']);
});

it('still reads twenty courses, each with a batch in session and an intake, in one statement', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 12:00:00', 'UTC'));
    $counts = [];

    foreach ([1, 20] as $size) {
        while (Course::query()->count() < $size) {
            $course = Course::factory()->create(['is_active' => true]);
            Batch::factory()->for($course)->active()->create(['start_date' => '2026-09-01']);
            Batch::factory()->for($course)->active()->create(['start_date' => '2026-11-01']);
        }

        DB::enableQueryLog();
        DB::flushQueryLog();
        $cards = app(NextUpcomingBatch::class)->forCoursesWithSessions();
        $counts[] = count(DB::getQueryLog());
        DB::disableQueryLog();

        expect($cards)->toHaveCount($size);
    }

    expect($counts)->toBe([1, 1]);
});

it('keeps capacity and hour SQL scopes in agreement with their predicates', function () {
    $course = Course::factory()->create(['total_hours' => 30]);
    $departed = User::factory()->create();
    $departed->delete();
    foreach ([[0, 2, null, 0], [1, 2, null, 20], [2, 2, null, 30], [3, 2, 10, 30], [2, 1, 0, 0]] as [$capacity, $active, $hours, $assigned]) {
        $batch = Batch::factory()->for($course)->create(['capacity' => $capacity, 'total_hours' => $hours]);
        Enrollment::factory()->count($active)->for($batch)->create();
        Enrollment::factory()->for($batch)->create(['status' => EnrollmentStatus::Withdrawn]);
        Enrollment::factory()->for($batch)->create(['status' => EnrollmentStatus::Completed]);
        if ($assigned > 0) {
            $batch->instructors()->attach($departed, ['assigned_hours' => $assigned]);
        }
    }
    $batches = Batch::query()->with('course')->get();
    expect(Batch::query()->overCapacity()->pluck('id')->all())
        ->toBe($batches->filter->isOverCapacity()->pluck('id')->all())
        ->and(Batch::query()->hourMismatch()->pluck('id')->all())
        ->toBe($batches->filter->hasHourMismatch()->pluck('id')->all());
});
