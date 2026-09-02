<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Filament\Resources\StudentCertificateResource\Pages;

use App\Domain\Enrollment\Enums\CertificateStatus;
use App\Domain\Enrollment\Filament\Resources\StudentCertificateResource;
use App\Domain\Enrollment\Models\StudentCertificate;
use Filament\Infolists\Components\TextEntry;
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
        return $schema->components([
            TextEntry::make('reference_number')
                ->label(__('certificates.reference_number')),

            TextEntry::make('student_name')
                ->label(__('certificates.student')),

            TextEntry::make('course_name')
                ->label(__('certificates.course')),

            TextEntry::make('completed_on')
                ->label(__('certificates.completed_on'))
                ->date(),

            TextEntry::make('issued_at')
                ->label(__('certificates.issued_at'))
                ->dateTime(),

            TextEntry::make('issuedBy.name')
                ->label(__('certificates.issued_by')),

            TextEntry::make('status')
                ->label(__('certificates.status'))
                ->badge()
                ->formatStateUsing(fn (CertificateStatus $state): string => $state->label())
                ->color(fn (CertificateStatus $state): string => match ($state) {
                    CertificateStatus::Valid => 'success',
                    CertificateStatus::Replaced => 'gray',
                    CertificateStatus::Revoked => 'danger',
                }),

            // The certificate this one superseded, if it was issued as a
            // replacement — StudentCertificate::replaces()'s own docblock:
            // the pointer lives on this (the newer) row.
            TextEntry::make('replaces.reference_number')
                ->label(__('certificates.replaces'))
                ->visible(fn (StudentCertificate $record): bool => $record->replaces_certificate_id !== null),

            // Nothing about a revocation is erased elsewhere on this page —
            // these three are additional facts, hidden entirely rather than
            // shown blank when the certificate was never revoked, matching
            // ViewCharge's identical treatment of its write-off fields.
            TextEntry::make('revoked_at')
                ->label(__('certificates.revoked_at'))
                ->dateTime()
                ->visible(fn (StudentCertificate $record): bool => $record->status === CertificateStatus::Revoked),

            TextEntry::make('revokedBy.name')
                ->label(__('certificates.revoked_by'))
                ->visible(fn (StudentCertificate $record): bool => $record->status === CertificateStatus::Revoked),

            TextEntry::make('revocation_reason')
                ->label(__('certificates.revocation_reason'))
                ->visible(fn (StudentCertificate $record): bool => $record->status === CertificateStatus::Revoked),
        ]);
    }
}
