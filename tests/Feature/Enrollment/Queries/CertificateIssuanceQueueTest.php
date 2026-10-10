<?php

declare(strict_types=1);

use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\StudentCertificate;
use App\Domain\Enrollment\Queries\CertificateIssuanceQueue;
use App\Domain\Finance\Models\Charge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('includes completed debtors without a valid certificate and preserves revoked history', function () {
    $owing = Enrollment::factory()->create(['status' => EnrollmentStatus::Completed]);
    Charge::factory()->for($owing)->create();
    $revoked = Enrollment::factory()->create(['status' => EnrollmentStatus::Completed]);
    StudentCertificate::factory()->revoked()->for($revoked)->create();
    $replaced = Enrollment::factory()->create(['status' => EnrollmentStatus::Completed]);
    StudentCertificate::factory()->replaced()->for($replaced)->create();
    $issued = Enrollment::factory()->create(['status' => EnrollmentStatus::Completed]);
    StudentCertificate::factory()->for($issued)->create();
    Enrollment::factory()->create();
    Enrollment::factory()->create(['status' => EnrollmentStatus::Withdrawn]);

    expect(app(CertificateIssuanceQueue::class)->query()->pluck('enrollments.id')->all())
        ->toBe([$owing->id, $revoked->id, $replaced->id]);
});

it('loads queue rows and their labels with fixed statement count', function () {
    $counts = [];
    foreach ([1, 20] as $size) {
        Enrollment::factory()->count($size === 1 ? 1 : 19)->create(['status' => EnrollmentStatus::Completed]);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $rows = app(CertificateIssuanceQueue::class)->query()->get();
        foreach ($rows as $row) {
            expect($row->student->first_name)->toBeString();
            expect($row->batch->course->name_en)->toBeString();
        }
        expect($rows)->toHaveCount($size);
        $counts[] = count(DB::getQueryLog());
        DB::disableQueryLog();
    }
    expect($counts)->toBe([4, 4]);
});
