<?php

declare(strict_types=1);

use App\Domain\Enrollment\Filament\Resources\BatchResource\Pages\ViewBatch;
use App\Domain\Enrollment\Filament\Resources\BatchResource\RelationManagers\EnrollmentsRelationManager;
use App\Domain\Enrollment\Filament\Resources\CourseResource;
use App\Domain\Enrollment\Filament\Resources\CourseResource\Pages\ListCourses;
use App\Domain\Enrollment\Filament\Resources\StudentResource\Pages\ListStudents;
use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Course;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\Student;
use App\Domain\Finance\Enums\TenderMethod;
use App\Domain\Finance\Filament\Resources\PaymentResource\Pages\ListPayments;
use App\Domain\Finance\Models\Charge;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Models\PaymentAllocation;
use App\Domain\Finance\Models\PaymentTender;
use App\Domain\Finance\Services\StudentBalanceQuery;
use App\Domain\Finance\Support\Money;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
    $this->admin = User::factory()->create(['is_active' => true]);
    app(SystemRoleWriter::class)->assignRoles($this->admin, 'admin');
    $this->admin->refresh()->can('view_any_payment');
});

/**
 * Capture the actual list mount and the data consumed by its future columns.
 * Auth/cache statements are excluded; aggregate subqueries count once with
 * their containing SELECT, never as an extra statement.
 *
 * @param  Closure(): void  $render
 */
function measuredListReads(Closure $render): int
{
    $statements = captureStatements();
    $render();

    return collect($statements)->filter(fn (array $statement): bool => str_starts_with($statement['sql'], 'select')
        && preg_match('/\b(?:from|join) `?(students|courses|batches|enrollments|charges|payments|payment_tenders|payment_allocations)`?\b/', $statement['sql']) === 1
    )->count();
}

function listSplitPayment(User $actor): Payment
{
    $charge = Charge::factory()->create(['list_price' => '100.001']);
    $payment = Payment::factory()->create([
        'student_id' => $charge->enrollment->student_id,
        'recorded_by' => $actor->getKey(),
    ]);
    PaymentTender::factory()->for($payment)->create(['amount' => '20.001']);
    PaymentTender::factory()->card()->for($payment)->create(['amount' => '30.002']);
    PaymentAllocation::factory()->for($payment)->for($charge)->create(['amount' => '50.003']);

    return $payment;
}

function listStudentWithBalance(User $actor, ?Batch $roster = null): Student
{
    $student = Student::factory()->create();
    $enrollment = Enrollment::factory()->for($student)->for($roster ?? Batch::factory())->create();
    $charge = Charge::factory()->for($enrollment)->create(['list_price' => '100.001']);
    $payment = Payment::factory()->for($student)->create(['recorded_by' => $actor->getKey()]);
    PaymentTender::factory()->for($payment)->create(['amount' => '50.003']);
    PaymentAllocation::factory()->for($payment)->for($charge)->create(['amount' => '50.003']);
    $reversed = Payment::factory()->reversed($actor)->for($student)->create(['recorded_by' => $actor->getKey()]);
    PaymentTender::factory()->for($reversed)->create(['amount' => '4.005']);
    PaymentAllocation::factory()->for($reversed)->for($charge)->create(['amount' => '4.005']);
    $withdrawn = Enrollment::factory()->withdrawn()->for($student)->create();
    Charge::factory()->writtenOff($actor)->for($withdrawn)->create(['list_price' => '200.002']);
    Enrollment::factory()->for($student)->create();

    return $student;
}

it('loads student balances in bounded statements at two multi-row sizes', function (bool $roster) {
    $batch = $roster ? Batch::factory()->active()->create() : null;
    $counts = [];

    foreach ([2, 8] as $size) {
        foreach (range(count($counts) === 0 ? 1 : 3, $size) as $ignored) {
            listStudentWithBalance($this->admin, $batch);
        }

        $counts[$size] = measuredListReads(function () use ($roster, $batch, $size): void {
            $component = $roster
                ? Livewire::actingAs($this->admin)->test(EnrollmentsRelationManager::class, [
                    'ownerRecord' => $batch,
                    'pageClass' => ViewBatch::class,
                ])->assertSuccessful()
                : Livewire::actingAs($this->admin)->test(ListStudents::class)->assertSuccessful();
            $records = $component->instance()->getTableRecords();
            expect($records->count())->toBe($size);
            foreach ($records as $record) {
                $student = $roster ? $record->student : $record;
                expect(array_key_exists(StudentBalanceQuery::OUTSTANDING_ALIAS, $student->getAttributes()))->toBeTrue();
                expect(Money::fromDecimal($student->{StudentBalanceQuery::OUTSTANDING_ALIAS})->toDecimal())->toBe('250.000');
            }
        });
    }

    expect($counts[2])->toBeGreaterThan(0)->toBeLessThanOrEqual($roster ? 3 : 2)
        ->and($counts[8])->toBe($counts[2], ($roster ? 'Roster' : 'Student').' SELECT counts: '.json_encode($counts));
})->with(['student register' => false, 'batch roster' => true]);

it('loads split tenders for payment lists in bounded statements at two multi-row sizes', function () {
    $counts = [];

    foreach ([2, 8] as $size) {
        foreach (range(count($counts) === 0 ? 1 : 3, $size) as $ignored) {
            listSplitPayment($this->admin);
        }

        $counts[$size] = measuredListReads(function () use ($size): void {
            $component = Livewire::actingAs($this->admin)->test(ListPayments::class)->assertSuccessful();
            $records = $component->instance()->getTableRecords();
            expect($records->count())->toBe($size);

            foreach ($records as $payment) {
                expect($payment->relationLoaded('tenders'))->toBeTrue();
                $tenders = $payment->tenders->sortBy('id');
                expect($tenders->pluck('amount')->all())->toBe(['20.001', '30.002'])
                    ->and($tenders->pluck('method')->all())->toBe([TenderMethod::Cash, TenderMethod::Card])
                    ->and($payment->tenders_sum_amount)->toBe('50.003');
            }
        });
    }

    expect($counts[2])->toBeGreaterThan(0)->toBeLessThanOrEqual(6)
        ->and($counts[8])->toBe($counts[2], 'Payment SELECT counts: '.json_encode($counts));
});

it('loads batch and distinct enrolled-student counts for course lists in bounded statements at two multi-row sizes', function () {
    $counts = [];
    $expected = [];

    foreach ([2, 8] as $size) {
        foreach (range(count($expected) + 1, $size) as $index) {
            $course = Course::factory()->create();
            $batchCount = $index % 2 === 0 ? 3 : 0;
            if ($batchCount > 0) {
                $batches = Batch::factory()->count($batchCount)->for($course)->create();
                $student = Student::factory()->create();
                Enrollment::factory()->for($student)->for($batches[0])->create();
                Enrollment::factory()->completed()->for($student)->for($batches[1])->create();
                Enrollment::factory()->withdrawn()->for($batches[0])->create();
                Enrollment::factory()->completed()->for($batches[2])->create();
            }
            $expected[$course->getKey()] = $batchCount;
        }

        $counts[$size] = measuredListReads(function () use ($size, $expected): void {
            $component = Livewire::actingAs($this->admin)->test(ListCourses::class)->assertSuccessful();
            $records = $component->instance()->getTableRecords();
            expect($records->count())->toBe($size);
            foreach ($records as $course) {
                expect(array_key_exists('batches_count', $course->getAttributes()))->toBeTrue()
                    ->and((int) $course->batches_count)->toBe($expected[$course->getKey()]);
                expect(array_key_exists(CourseResource::ENROLLED_STUDENTS_COUNT, $course->getAttributes()))->toBeTrue()
                    ->and((int) $course->{CourseResource::ENROLLED_STUDENTS_COUNT})->toBe($expected[$course->getKey()] === 0 ? 0 : 2);
            }
        });
    }

    expect($counts[2])->toBeGreaterThan(0)->toBeLessThanOrEqual(2, 'Course SELECT counts: '.json_encode($counts))
        ->and($counts[8])->toBe($counts[2], 'Course SELECT counts: '.json_encode($counts));
});

it('keeps zero balances and pagination on student lists', function (bool $roster) {
    $batch = $roster ? Batch::factory()->active()->create() : null;
    $students = Student::factory()->count(12)->create();
    if ($batch !== null) {
        foreach ($students as $student) {
            Enrollment::factory()->for($student)->for($batch)->create();
        }
    }

    $component = $roster
        ? Livewire::actingAs($this->admin)->test(EnrollmentsRelationManager::class, [
            'ownerRecord' => $batch,
            'pageClass' => ViewBatch::class,
        ])->assertSuccessful()
        : Livewire::actingAs($this->admin)->test(ListStudents::class)->assertSuccessful();
    $component->set('tableRecordsPerPage', 5);
    $firstPage = $component->instance()->getTableRecords();
    expect($firstPage->count())->toBe(5)->and($firstPage->total())->toBe(12);

    $component->call('gotoPage', 2, $component->instance()->getTablePaginationPageName());
    $secondPage = $component->instance()->getTableRecords();
    expect($secondPage->count())->toBe(5)->and($secondPage->total())->toBe(12)
        ->and($firstPage->pluck('id')->intersect($secondPage->pluck('id'))->all())->toBe([]);
    foreach ($secondPage as $record) {
        $student = $roster ? $record->student : $record;
        expect($student->{StudentBalanceQuery::OUTSTANDING_ALIAS})->toBe('0.000');
    }
})->with(['student register' => false, 'batch roster' => true]);

it('does not load balances for staff without finance read permission', function (bool $roster) {
    $staff = User::factory()->create(['is_active' => true]);
    app(SystemRoleWriter::class)->assignRoles($staff, 'staff');
    $staff->refresh();
    expect($staff->can('view_any_charge'))->toBeFalse();
    $batch = $roster ? Batch::factory()->active()->create() : null;
    listStudentWithBalance($this->admin, $batch);
    listStudentWithBalance($this->admin, $batch);

    $component = $roster
        ? Livewire::actingAs($staff)->test(EnrollmentsRelationManager::class, [
            'ownerRecord' => $batch,
            'pageClass' => ViewBatch::class,
        ])->assertSuccessful()
        : Livewire::actingAs($staff)->test(ListStudents::class)->assertSuccessful();
    $records = $component->instance()->getTableRecords();
    expect($records->count())->toBe(2);
    foreach ($records as $record) {
        $student = $roster ? $record->student : $record;
        expect(array_key_exists(StudentBalanceQuery::OUTSTANDING_ALIAS, $student->getAttributes()))->toBeFalse();
    }
})->with(['student register' => false, 'batch roster' => true]);
