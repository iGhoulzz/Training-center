<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Data;

final class EnrollStudentData
{
    public function __construct(
        public readonly int $studentId,
        public readonly int $batchId,
    ) {}
}
