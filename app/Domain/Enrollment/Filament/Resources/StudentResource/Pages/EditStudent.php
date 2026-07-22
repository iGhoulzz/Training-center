<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Filament\Resources\StudentResource\Pages;

use App\Domain\Enrollment\Filament\Resources\StudentResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

/**
 * Editing a student record.
 *
 * Access is gated by EditRecord::authorizeAccess(), which aborts 403 unless the
 * actor passes StudentPolicy::update() — so view-only staff never reach this
 * page at all.
 */
class EditStudent extends EditRecord
{
    protected static string $resource = StudentResource::class;

    /**
     * The type MUST be ?bool — Filament declares the property as ?bool in
     * Filament\Pages\Concerns\CanUseDatabaseTransactions, and narrowing it to
     * bool is a fatal incompatible-property-type error.
     */
    protected ?bool $hasDatabaseTransactions = true;

    /**
     * Deleting a student is a soft delete: the row leaves the register but
     * survives for the enrolments and paperwork that reference it.
     *
     * authorize(), not merely visible(). visible() is a UX affordance that a
     * crafted Livewire mount ignores; authorize('delete') runs
     * StudentPolicy::delete() against this record, and an unauthorized action
     * is hidden AND unmountable (CanBeDisabled::isDisabled() consults
     * isAuthorized(), and InteractsWithActions::mountAction() refuses a
     * disabled action). That matters because update_student and delete_student
     * are separate grants: reaching this page does not imply the right to
     * delete from it.
     *
     * A NOTE ON WHAT FILAMENT DOES BY DEFAULT, because UserResource's comment
     * is easy to over-read. "Authorization defaults to null (allowed for all
     * users)" (Filament\Actions\Concerns\CanBeAuthorized) is true of CUSTOM
     * actions — Action::make('resetPassword') and the like. Filament's own
     * typed actions on a RESOURCE page are different: with authorization left
     * null, resolveIsAuthorized() falls back to
     * Resources\Pages\Page::getDefaultActionAuthorizationResponse(), which maps
     * DeleteAction to the resource's delete policy check. Verified by mutation:
     * replacing this line with ->visible(true) still denies the actor.
     *
     * The explicit call stays regardless. It costs nothing, it states the
     * ability at the call site, and it does not depend on a framework default
     * mapping that a future upgrade could quietly change. The mutation probe
     * that proves the test can see a failure is ->authorize('view'), which
     * overrides the default with an ability the actor holds and makes
     * StudentResourceTest fail on the hidden assertion.
     */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->authorize('delete')
                ->successRedirectUrl(fn (): string => StudentResource::getUrl('index')),
        ];
    }
}
