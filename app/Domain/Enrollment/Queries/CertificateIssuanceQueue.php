<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Queries;

use App\Domain\Enrollment\Enums\CertificateStatus;
use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\StudentCertificate;
use Illuminate\Database\Eloquent\Builder;

/** Completed enrolments without a valid certificate, regardless of debt. */
final class CertificateIssuanceQueue
{
    /** @return Builder<Enrollment> */
    public function query(): Builder
    {
        return Enrollment::query()
            ->where('status', EnrollmentStatus::Completed)
            ->whereNotIn('id', StudentCertificate::query()
                ->select('enrollment_id')->where('status', CertificateStatus::Valid))
            ->with(['student', 'batch.course'])
            ->orderBy('id');
    }
}
