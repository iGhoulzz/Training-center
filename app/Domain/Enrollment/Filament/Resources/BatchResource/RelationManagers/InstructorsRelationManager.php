<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Filament\Resources\BatchResource\RelationManagers;

use App\Domain\Enrollment\Actions\AssignInstructorAction;
use App\Domain\Enrollment\Actions\RemoveInstructorAction;
use App\Domain\Enrollment\Data\AssignInstructorData;
use App\Domain\Enrollment\Exceptions\BatchClosedException;
use App\Domain\Enrollment\Exceptions\InstructorNotEligibleException;
use App\Domain\Enrollment\Models\Batch;
use App\Domain\Staff\Models\StaffProfile;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * Who teaches this batch, and for how many hours (P1-T10).
 *
 * EVERY WRITE ROUTES THROUGH AN ACTION — NONE THROUGH THE RELATION
 * ---------------------------------------------------------------
 * Filament's AttachAction, DetachAction and the relation manager's EditAction
 * persist by calling attach() / detach() / updateExistingPivot() on the
 * relation. Each of those reaches around AssignInstructorAction, and with it the
 * closed-batch refusal, the instructor-eligibility rule, the row lock, and the
 * actor check. None of them is registered here. The three buttons below are
 * plain Actions with their own handlers, so there is no built-in persistence to
 * reach in the first place.
 *
 * VISIBILITY IS AUTHORIZED AGAINST THE BATCH, NOT THE USER MODEL
 * --------------------------------------------------------------
 * RelationManager::canViewForRecord() defaults to authorizing viewAny on the
 * RELATED model — here App\Models\User — which would demand view_any_user from
 * anyone wanting to see who teaches a batch. That is the wrong question: reading
 * a batch's instructors is reading the batch. canViewForRecord() below asks
 * BatchPolicy::view() about the owner record instead.
 *
 * For the same reason each action's authorize() takes a closure asking
 * BatchPolicy::assignInstructor() about the OWNER record. A bare
 * authorize('assignInstructor') would pass Filament's default argument — the
 * related User — to a policy that governs batches. An unauthorized action is
 * hidden AND unmountable (isDisabled() consults isHidden()), so a crafted
 * Livewire mount is refused at the same gate the button is; the Actions
 * themselves then re-authorize server-side, which is the real boundary.
 *
 * NO BULK ACTIONS
 * ---------------
 * Filament authorizes a bulk action once against a *Any policy method and never
 * consults the per-record one, so a bulk detach could not express the
 * closed-batch refusal at all, and would call the relation's detach() directly.
 * BatchPolicy defines no deleteAny(); nothing here would consult it anyway.
 */
class InstructorsRelationManager extends RelationManager
{
    protected static string $relationship = 'instructors';

    protected static ?string $relatedResource = null;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('enrollment.instructors');
    }

    /**
     * Seeing who teaches a batch is part of seeing the batch.
     *
     * See the class docblock: the inherited implementation would authorize
     * viewAny against App\Models\User and hide this panel from every front-desk
     * user, who hold view_batch and no user permission at all.
     */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Gate::allows('view', $ownerRecord);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->label(__('enrollment.instructor'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('staffProfile.job_title')
                    ->label(__('staff.job_title'))
                    ->placeholder(__('enrollment.no_job_title')),

                // The pivot value, which is the entire point of this panel: the
                // hours belong to the pair, not to the batch and not to the
                // account.
                TextColumn::make('assigned_hours')
                    ->label(__('enrollment.assigned_hours'))
                    ->state(fn (User $record): int => self::pivotHours($record))
                    ->numeric(),
            ])
            ->headerActions([
                $this->assignAction(),
            ])
            ->recordActions([
                $this->editHoursAction(),
                $this->removeAction(),
            ]);
        // No bulk actions, deliberately. See the class docblock.
    }

    /**
     * Put an instructor on the batch, or change one already on it.
     *
     * One button for both, because AssignInstructorAction is idempotent: naming
     * an instructor who is already assigned updates the hours rather than
     * inserting a second row. A separate "attach" that failed on a duplicate
     * would surface the unique index as a driver error.
     */
    private function assignAction(): Action
    {
        return Action::make('assign')
            ->label(__('enrollment.assign_instructor'))
            ->icon(Heroicon::OutlinedUserPlus)
            ->modalHeading(__('enrollment.assign_instructor'))
            ->authorize(fn (): bool => Gate::allows('assignInstructor', $this->getOwnerRecord()))
            ->schema([
                Select::make('user_id')
                    ->label(__('enrollment.instructor'))
                    ->required()
                    /*
                     * Only accounts that actually teach here, and only active
                     * ones. This is the half of the eligibility rule that stops
                     * a name being offered; AssignInstructorAction re-checks the
                     * same rule server-side, because a Select's options are a
                     * suggestion and the submitted value is user input.
                     */
                    ->options(fn (): array => self::eligibleInstructors())
                    ->searchable()
                    ->preload(),

                TextInput::make('assigned_hours')
                    ->label(__('enrollment.assigned_hours'))
                    ->helperText(__('enrollment.assigned_hours_hint'))
                    ->numeric()
                    ->integer()
                    ->minValue(0)
                    // unsignedSmallInteger: the column's ceiling.
                    ->maxValue(AssignInstructorData::MAX_ASSIGNED_HOURS)
                    ->required(),
            ])
            ->action(function (array $data): void {
                $this->assign(
                    (int) $data['user_id'],
                    (int) $data['assigned_hours'],
                );
            });
    }

    /**
     * Change one instructor's hours, prefilled with the current figure.
     *
     * Routes through the same Action as assignment, for the same reason: an
     * EditAction here would write the pivot through the relation.
     */
    private function editHoursAction(): Action
    {
        return Action::make('edit_hours')
            ->label(__('enrollment.edit_assigned_hours'))
            ->icon(Heroicon::OutlinedPencilSquare)
            ->modalHeading(__('enrollment.edit_assigned_hours'))
            ->authorize(fn (): bool => Gate::allows('assignInstructor', $this->getOwnerRecord()))
            ->fillForm(fn (User $record): array => [
                'assigned_hours' => self::pivotHours($record),
            ])
            ->schema([
                TextInput::make('assigned_hours')
                    ->label(__('enrollment.assigned_hours'))
                    ->helperText(__('enrollment.assigned_hours_hint'))
                    ->numeric()
                    ->integer()
                    ->minValue(0)
                    ->maxValue(AssignInstructorData::MAX_ASSIGNED_HOURS)
                    ->required(),
            ])
            ->action(function (array $data, User $record): void {
                $this->assign((int) $record->getKey(), (int) $data['assigned_hours']);
            });
    }

    /**
     * Take one instructor off the batch, leaving any co-teacher in place.
     */
    private function removeAction(): Action
    {
        return Action::make('remove')
            ->label(__('enrollment.remove_instructor'))
            ->icon(Heroicon::OutlinedUserMinus)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('enrollment.remove_instructor'))
            ->authorize(fn (): bool => Gate::allows('assignInstructor', $this->getOwnerRecord()))
            ->action(function (User $record): void {
                /** @var User $actor */
                $actor = auth()->user();

                /** @var Batch $batch */
                $batch = $this->getOwnerRecord();

                try {
                    app(RemoveInstructorAction::class)->execute($actor, $batch, $record);
                } catch (BatchClosedException|AuthorizationException $exception) {
                    self::refuse($exception->getMessage());
                }
            });
    }

    /**
     * The one place the relation manager writes, so the one place that has to
     * translate the Actions' typed refusals into something readable.
     *
     * AuthorizationException is caught rather than allowed to become a 403 page:
     * the button is already hidden for an unentitled actor, so reaching here
     * means the grant or the batch's status changed under an open modal, and a
     * notification says so without discarding whatever else is on screen.
     */
    private function assign(int $instructorId, int $assignedHours): void
    {
        /** @var User $actor */
        $actor = auth()->user();

        /** @var Batch $batch */
        $batch = $this->getOwnerRecord();

        try {
            app(AssignInstructorAction::class)->execute($actor, new AssignInstructorData(
                batchId: (int) $batch->getKey(),
                instructorId: $instructorId,
                assignedHours: $assignedHours,
            ));
        } catch (BatchClosedException|InstructorNotEligibleException|AuthorizationException $exception) {
            self::refuse($exception->getMessage());
        }
    }

    private static function refuse(string $message): void
    {
        Notification::make()
            ->title($message)
            ->danger()
            ->persistent()
            ->send();
    }

    /**
     * Active accounts whose staff profile says they teach.
     *
     * scopeInstructors() on StaffProfile is the single expression of "this
     * person teaches"; driving the subquery from StaffProfile rather than
     * repeating the enum comparison in a whereHas closure keeps the offered list
     * and the profile register answering the same question.
     *
     * @return array<int, string>
     */
    private static function eligibleInstructors(): array
    {
        return User::query()
            ->active()
            ->whereIn('users.id', StaffProfile::query()->instructors()->select('user_id'))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * The assigned_hours carried on the pivot of a row in this relation.
     *
     * Reached through the pivot accessor rather than a fresh query: the relation
     * declares withPivot('assigned_hours'), so every row already carries it and
     * a lookup here would be one query per instructor listed.
     */
    private static function pivotHours(User $record): int
    {
        $pivot = $record->getAttribute('pivot');

        return $pivot instanceof Model ? (int) $pivot->getAttribute('assigned_hours') : 0;
    }
}
