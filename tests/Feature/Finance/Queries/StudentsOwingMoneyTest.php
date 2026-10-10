<?php

declare(strict_types=1);

use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\Student;
use App\Domain\Finance\Models\Charge;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Models\PaymentAllocation;
use App\Domain\Finance\Queries\StudentsOwingMoney;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('returns unique student ids for positive debt including withdrawn and written-off rows', function () {
    $owing = Student::factory()->create();
    Charge::factory()->for(Enrollment::factory()->for($owing))->create(['amount' => '0.001']);
    Charge::factory()->for(Enrollment::factory()->for($owing))->create(['amount' => '1.000']);
    $withdrawn = Enrollment::factory()->create(['status' => EnrollmentStatus::Withdrawn]);
    Charge::factory()->writtenOff()->for($withdrawn)->create(['amount' => '2.000']);
    $paid = Charge::factory()->create(['amount' => '3.000']);
    PaymentAllocation::factory()->for($paid, 'charge')->create(['amount' => '3.000']);
    $reversed = Charge::factory()->create(['amount' => '4.000']);
    PaymentAllocation::factory()->for($reversed, 'charge')->for(Payment::factory()->reversed())->create(['amount' => '4.000']);
    Charge::factory()->create(['amount' => '0.000']);
    Enrollment::factory()->create();

    expect(app(StudentsOwingMoney::class)->studentIds()->all())
        ->toBe([$owing->id, $withdrawn->student_id, $reversed->enrollment->student_id]);
});

it('selects one or twenty debtors in one statement', function () {
    $counts = [];
    foreach ([1, 20] as $size) {
        Charge::factory()->count($size === 1 ? 1 : 19)->create();
        DB::enableQueryLog();
        DB::flushQueryLog();
        expect(app(StudentsOwingMoney::class)->studentIds())->toHaveCount($size);
        $counts[] = count(DB::getQueryLog());
        DB::disableQueryLog();
    }
    expect($counts)->toBe([1, 1]);
});
