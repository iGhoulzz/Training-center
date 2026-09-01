<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Filament\Resources\StudentCertificateResource\Pages;

use App\Domain\Enrollment\Filament\Resources\StudentCertificateResource;
use Filament\Resources\Pages\ListRecords;

/**
 * The certificate register, listed. Read-plus-three-actions — see
 * `StudentCertificateResource`'s own docblock.
 *
 * issueAction() IS THE ONLY HEADER ACTION, AND THERE IS NO CREATE PAGE TO LINK
 * TO.
 * -------------------------------------------------------------------------------
 * `StudentCertificatePolicy::create()` refuses unconditionally (T4) and
 * `StudentCertificateResource::canCreate()` says so explicitly. Issuing a
 * certificate is not "creating a certificate resource record" in Filament's
 * sense — it is a domain transition with its own enrolment picker — which is
 * why it is a plain `Action`, never a `CreateAction`, matching
 * docs/ENGINEERING.md's note that only NOT registering a create route closes
 * that handler off.
 */
class ListStudentCertificates extends ListRecords
{
    protected static string $resource = StudentCertificateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            StudentCertificateResource::issueAction(),
        ];
    }
}
