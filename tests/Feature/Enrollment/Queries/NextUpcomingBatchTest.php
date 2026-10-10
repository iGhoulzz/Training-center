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
    foreach ([BatchStatus::Active, BatchStatus::Cancelled, BatchStatus::Completed] as $status) {
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
