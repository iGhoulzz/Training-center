<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Filament\Resources\StudentCertificateResource\Pages;

use App\Domain\Enrollment\Enums\CertificateStatus;
use App\Domain\Enrollment\Filament\Resources\StudentCertificateResource;
use App\Domain\Enrollment\Models\StudentCertificate;
use App\Filament\Details\AdminDetails;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;

/**
 * One certificate in full, read-only aside from the two record actions.
 *
 * THE SAME TWO ACTIONS AS THE TABLE ROW, NOT A SEPARATE COPY
 * ------------------------------------------------------------
 * `getHeaderActions()` calls `StudentCertificateResource::replaceAction()` and
 * `revokeAction()` — the same shared builders `StudentCertificateResource::table()`
 * uses — so the list and this page cannot drift apart on what "replace" or
 * "revoke" does, matching `ViewCharge`'s identical reasoning.
 */
class ViewStudentCertificate extends ViewRecord
{
    protected static string $resource = StudentCertificateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            StudentCertificateResource::replaceAction(),
            StudentCertificateResource::revokeAction(),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return AdminDetails::schema($schema, [
            AdminDetails::section(__('certificates.printed_details'), [
                AdminDetails::code('reference_number', __('certificates.reference_number')),

                AdminDetails::text('student_name', __('certificates.student')),

                AdminDetails::text('course_name', __('certificates.course')),

                AdminDetails::date('completed_on', __('certificates.completed_on')),
            ]),
            AdminDetails::section(__('certificates.issuance_record'), [
                AdminDetails::dateTime('issued_at', __('certificates.issued_at')),

                AdminDetails::person('issuedBy.name', __('certificates.issued_by')),

                AdminDetails::status(
                    'status',
                    __('certificates.status'),
                    fn (CertificateStatus $state): string => $state->label(),
                    fn (CertificateStatus $state): string => match ($state) {
                        CertificateStatus::Valid => 'success',
                        CertificateStatus::Replaced => 'gray',
                        CertificateStatus::Revoked => 'danger',
                    },
                ),

                // The certificate this one superseded, if it was issued as a
                // replacement — StudentCertificate::replaces()'s own docblock:
                // the pointer lives on this (the newer) row.
                AdminDetails::code('replaces.reference_number', __('certificates.replaces'))
                    ->visible(fn (StudentCertificate $record): bool => $record->replaces_certificate_id !== null),

                // Nothing about a revocation is erased elsewhere on this page —
                // these three are additional facts, hidden entirely rather than
                // shown blank when the certificate was never revoked, matching
                // ViewCharge's identical treatment of its write-off fields.
                AdminDetails::dateTime('revoked_at', __('certificates.revoked_at'))
                    ->visible(fn (StudentCertificate $record): bool => $record->status === CertificateStatus::Revoked),

                AdminDetails::person('revokedBy.name', __('certificates.revoked_by'))
                    ->visible(fn (StudentCertificate $record): bool => $record->status === CertificateStatus::Revoked),

                AdminDetails::longText('revocation_reason', __('certificates.revocation_reason'))
                    ->visible(fn (StudentCertificate $record): bool => $record->status === CertificateStatus::Revoked),
            ]),
        ]);
    }
}
