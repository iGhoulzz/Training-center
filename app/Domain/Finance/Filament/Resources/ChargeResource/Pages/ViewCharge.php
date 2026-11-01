<?php

declare(strict_types=1);

namespace App\Domain\Finance\Filament\Resources\ChargeResource\Pages;

use App\Domain\Enrollment\Filament\Resources\StudentResource;
use App\Domain\Enrollment\Services\EnrollmentQueryService;
use App\Domain\Finance\Filament\Resources\ChargeResource;
use App\Domain\Finance\Models\Charge;
use App\Domain\Finance\Support\ChargeBalance;
use App\Filament\Details\AdminDetails;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Schema;

/**
 * One bill in full, read-only aside from the two record actions.
 *
 * THE SAME TWO ACTIONS AS THE TABLE ROW, NOT A SEPARATE COPY
 * ------------------------------------------------------------
 * `getHeaderActions()` calls `ChargeResource::adjustAction()` and
 * `ChargeResource::writeOffAction()` — the same shared builders
 * `ChargeResource::table()` uses — so the list and this page cannot drift apart
 * on what "adjust" or "write off" does, the same reasoning
 * `BatchResource::deleteAction()` already establishes for this codebase.
 *
 * OUTSTANDING AND ALLOCATED ARE READ THROUGH ChargeBalance's PHP ENTRY POINT
 * HERE, NOT ITS SQL ONE
 * ---------------------------------------------------------------------------
 * This page renders exactly one charge, not a sortable table of them, so there
 * is no query to compose the SQL fragment into.
 * `ChargeBalance::outstandingFor()` / `allocatedFor()` run the identical SQL
 * string under the hood (see that class's own docblock) — the two answers
 * cannot disagree with what `ChargeResource::table()` shows for the same row.
 *
 * THE STUDENT LINE IS THE LIST'S, PLUS THE CODE (P4-T32, QA P3-16)
 * -----------------------------------------------------------------
 * The page named the enrolment, batch and course and never the person who owes
 * the bill. The record arrives through `ChargeResource::getEloquentQuery()`, so
 * it already carries the student attributes that query joined in; the line's
 * link is gated on them exactly as the list column's is, and a student who has
 * been soft-deleted still shows their name and code, with no link.
 *
 * The joined attributes carry the name but not the student's code, so the code
 * comes from `EnrollmentQueryService::receiptContextFor()` — the sanctioned way
 * to read it, and one bounded query for the page, not one per anything. Adding
 * the code to the join itself would remove even that one, and belongs with
 * `joinEnrollmentStudentLabelsTo()`.
 */
class ViewCharge extends ViewRecord
{
    protected static string $resource = ChargeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ChargeResource::adjustAction(),
            ChargeResource::writeOffAction(),
        ];
    }

    public function infolist(Schema $schema): Schema
    {
        return AdminDetails::schema($schema, [
            AdminDetails::section(__('charges.bill_details'), [
                AdminDetails::text('student_identity', __('charges.student'))
                    ->state(fn (Charge $record): string => __('charges.student_label', [
                        'code' => app(EnrollmentQueryService::class)->receiptContextFor((int) $record->enrollment_id)['student_code'],
                        'name' => trim($record->getAttribute(EnrollmentQueryService::ENROLLMENT_STUDENT_FIRST_NAME).' '.$record->getAttribute(EnrollmentQueryService::ENROLLMENT_STUDENT_LAST_NAME)),
                    ]))
                    ->url(fn (Charge $record): ?string => auth()->user()?->can('view_student') && auth()->user()->can('view_any_student')
                        && (bool) $record->getAttribute(EnrollmentQueryService::ENROLLMENT_STUDENT_AVAILABLE)
                        ? StudentResource::getUrl('index', ['student' => $record->getAttribute(EnrollmentQueryService::ENROLLMENT_STUDENT_ID)]) : null),

                AdminDetails::code('reference', __('charges.reference')),

                AdminDetails::code('enrollment.reference', __('charges.enrollment_reference')),

                AdminDetails::code('enrollment.batch.course.code', __('charges.course')),

                AdminDetails::code('enrollment.batch.code', __('charges.batch')),

                AdminDetails::money('list_price', __('charges.list_price'), 'charges.amount_lyd'),

                AdminDetails::text('discount_percentage', __('charges.discount'))
                    ->formatStateUsing(fn (?string $state): string => $state === null
                        ? __('charges.no_discount')
                        : __('charges.discount_percentage_value', ['percentage' => $state])),

                // The frozen figure — see Charge's own docblock on why this can
                // legitimately disagree with list_price and discount_percentage
                // after an AdjustChargeAction correction.
                AdminDetails::money('amount', __('charges.amount'), 'charges.amount_lyd'),

                AdminDetails::money('allocated', __('charges.allocated'), 'charges.amount_lyd')
                    ->state(fn (Charge $record): string => ChargeBalance::allocatedFor((int) $record->getKey())->toDecimal()),

                AdminDetails::money('outstanding', __('charges.outstanding'), 'charges.amount_lyd')
                    ->state(fn (Charge $record): string => ChargeBalance::outstandingFor((int) $record->getKey())->toDecimal()),

                AdminDetails::date('due_date', __('charges.due_date')),

                /*
                 * Nothing is erased by a write-off (design section 4) — these three
                 * columns are additional facts about the bill, not a replacement for
                 * anything above. Hidden entirely rather than shown blank when the
                 * charge was never written off, so the detail page does not read as
                 * though something is missing.
                 */
                AdminDetails::dateTime('written_off_at', __('charges.written_off_at'))
                    ->visible(fn (Charge $record): bool => $record->isWrittenOff()),

                AdminDetails::person('writtenOffBy.name', __('charges.written_off_by'))
                    ->visible(fn (Charge $record): bool => $record->isWrittenOff()),

                AdminDetails::longText('written_off_reason', __('charges.written_off_reason'))
                    ->visible(fn (Charge $record): bool => $record->isWrittenOff()),
            ], wide: true),
        ]);
    }
}
