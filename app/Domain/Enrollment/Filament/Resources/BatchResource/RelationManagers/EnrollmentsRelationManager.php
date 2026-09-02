<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Filament\Resources\BatchResource\RelationManagers;

use App\Domain\Enrollment\Actions\CompleteEnrollmentAction;
use App\Domain\Enrollment\Actions\DeleteEnrollmentAction;
use App\Domain\Enrollment\Actions\WithdrawEnrollmentAction;
use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Enrollment\Exceptions\BatchClosedException;
use App\Domain\Enrollment\Exceptions\DuplicateEnrollmentException;
use App\Domain\Enrollment\Exceptions\EnrollmentBatchChangedException;
use App\Domain\Enrollment\Exceptions\EnrollmentHasCertificateException;
use App\Domain\Enrollment\Exceptions\EnrollmentNotCompletableException;
use App\Domain\Enrollment\Exceptions\EnrollmentNotWithdrawableException;
use App\Domain\Enrollment\Exceptions\StudentNotEnrollableException;
use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\Student;
use App\Domain\Enrollment\Support\CompletionRule;
use App\Domain\Enrollment\Support\EnrollmentUpdateRule;
use App\Domain\Finance\Actions\EnrollAndBillAction;
use App\Domain\Finance\Data\EnrollAndBillData;
use App\Domain\Finance\Exceptions\ChargeAlreadyCommittedException;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/*
             * AUTHORIZATION IS RE-CHECKED PER ROW, INSIDE THE ACTION, UNDER THE
             * LOCK — and ->authorize() below is only a visibility hint.
             *
             * Filament 5.7 DOES offer a per-record hook —
             * CanBeAuthorized::authorizeIndividualRecords() — and an earlier
             * version of this comment claimed it did not, which review
             * corrected. The hook is not used because it would still decide
             * before the row is locked, and the assigned-batch rule has to be
             * read under the same lock the write takes or it answers from a
             * stale snapshot. CompletionRule does that inside
             * CompleteEnrollmentAction; this is the strictly stronger place.
             *
             * ->authorize() here is therefore coarse and non-binding: it keeps
             * the button off a toolbar the actor could never use, and decides
             * nothing.
             */
class EnrollmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'enrollments';

    protected static ?string $relatedResource = null;

    /**
     * How many students the picker offers for one search.
     *
     * A bound, not a preference. See searchStudents().
     */
    private const SEARCH_RESULT_LIMIT = 25;

    /** Memoised answer to "may this actor amend enrolments on this batch". */
    private ?bool $mayAmend = null;

    /** Memoised answer to "may this actor mark completion on this batch". */
    private ?bool $mayComplete = null;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('enrollment.enrollments');
    }

    /**
     * Seeing who is on a batch requires both seeing the batch and holding the
     * enrolment read grant.
     */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Gate::allows('view', $ownerRecord)
            && Gate::allows('viewAny', Enrollment::class);
    }

    /**
     * The bounded, server-side student search behind the picker.
     *
     * PUBLIC AND STATIC SO IT CAN BE TESTED AS ITSELF. Reaching it through the
     * mounted action's schema means asserting against Filament's component
     * internals, which change between releases and would make the test a
     * statement about the framework rather than about the search.
     *
     * student_code matches from the START — it is an identifier people read off a
     * form, so a prefix match is what they expect, and it stays index-friendly.
     * Either name matches anywhere, because people search for "zarrouk" without
     * knowing which field it lives in.
     *
     * @return array<int, string> student id => label
     */
    public static function searchStudents(string $search): array
    {
        return Student::query()
            ->where(fn (Builder $query): Builder => $query
                ->where('student_code', 'like', $search.'%')
                ->orWhere('first_name', 'like', '%'.$search.'%')
                ->orWhere('last_name', 'like', '%'.$search.'%'))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->limit(self::SEARCH_RESULT_LIMIT)
            ->get()
            ->mapWithKeys(fn (Student $student): array => [
                (int) $student->getKey() => self::studentLabel($student),
            ])
            ->all();
    }

    /**
     * The label for an already-selected student, redisplayed without searching.
     *
     * Null when the id resolves to nothing, which Filament renders as an empty
     * field rather than throwing.
     */
    public static function studentOptionLabel(mixed $value): ?string
    {
        $student = Student::query()->find($value);

        return $student instanceof Student ? self::studentLabel($student) : null;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            // Eager-load the student, or every row queries for its own name. The
            // relation is withTrashed(), so departed students still resolve.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('student'))
            ->defaultSort('enrolled_at', 'desc')
            ->columns([
                /*
                 * full_name IS AN ACCESSOR, NOT A COLUMN.
                 *
                 * Student::fullName() composes first_name and last_name in PHP;
                 * there is no `full_name` column, so a bare searchable() or
                 * sortable() here generates SQL against a column that does not
                 * exist and fails the first time somebody types in the search box.
                 *
                 * Search is therefore given an explicit query against the real
                 * columns. Sorting is NOT offered on this column at all — ordering
                 * a composed name means choosing whether "last, first" or "first
                 * last" is the order, which the centre has not been asked.
                 * student_code and enrolled_at are sortable instead, and both are
                 * real indexed columns.
                 */
                TextColumn::make('student.full_name')
                    ->label(__('enrollment.student'))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->whereHas('student', fn (Builder $student): Builder => $student
                            ->where('first_name', 'like', '%'.$search.'%')
                            ->orWhere('last_name', 'like', '%'.$search.'%'))),

                TextColumn::make('student.student_code')
                    ->label(__('enrollment.student_code'))
                    ->sortable(),

                TextColumn::make('status')
                    ->label(__('enrollment.status'))
                    ->badge()
                    ->formatStateUsing(fn (EnrollmentStatus $state): string => $state->label())
                    ->color(fn (EnrollmentStatus $state): string => match ($state) {
                        EnrollmentStatus::Active => 'success',
                        EnrollmentStatus::Completed => 'info',
                        EnrollmentStatus::Withdrawn => 'danger',
                    }),

                TextColumn::make('enrolled_at')
                    ->label(__('enrollment.enrolled_at'))
                    ->date()
                    ->sortable(),
            ])
            ->headerActions([$this->enrollAction()])
            ->recordActions([$this->withdrawAction(), $this->deleteAction()])
            // The one bulk action this table has. See the class docblock for
            // why this is safe while a bulk withdrawal or delete is not.
            ->toolbarActions([$this->completeAction()]);
    }

    /** Include code and name, so two students with the same name remain distinguishable. */
    private static function studentLabel(Student $student): string
    {
        return __('enrollment.student_option_label', [
            'code' => $student->student_code,
            'name' => $student->full_name,
        ]);
    }

    /**
     * May the current actor amend enrolments on the batch being viewed?
     *
     * ONE LOOKUP FOR THE WHOLE PANEL, NOT ONE PER ROW. Every row belongs to the
     * same batch, so this has one answer — but a per-record Gate::allows() asks
     * it once per row, and each ask is a pivot query.
     *
     * This is a RENDERING decision: which controls to show. It is not the
     * boundary. WithdrawEnrollmentAction re-asks the same rule under lock, and
     * that answer is what decides the write.
     */
    private function mayAmendThisBatch(): bool
    {
        /** @var User $actor */
        $actor = auth()->user();

        return $this->mayAmend ??= app(EnrollmentUpdateRule::class)->allows(
            $actor,
            (int) $this->getOwnerRecord()->getKey(),
        );
    }

    /**
     * May the current actor mark completion on the batch being viewed?
     *
     * The same one-lookup-for-the-whole-panel shape as mayAmendThisBatch(),
     * and the same caveat: this is what decides whether the bulk action is
     * SHOWN, not what decides any one row's write. CompleteEnrollmentAction
     * re-asks CompletionRule under lock, per row, inside completeAction()
     * below, and that answer is what binds.
     */
    private function mayCompleteThisBatch(): bool
    {
        /** @var User $actor */
        $actor = auth()->user();

        return $this->mayComplete ??= app(CompletionRule::class)->allows(
            $actor,
            (int) $this->getOwnerRecord()->getKey(),
        );
    }

    private function enrollAction(): Action
    {
        return Action::make('enroll')
            ->label(__('enrollment.enroll_student'))
            ->icon(Heroicon::OutlinedUserPlus)
            ->modalHeading(__('enrollment.enroll_student'))
            ->authorize(fn (): bool => Gate::allows('create', Enrollment::class))
            ->schema([
                Select::make('student_id')
                    ->label(__('enrollment.student'))
                    ->required()
                    ->searchable()
                    /*
                     * Server-side, bounded, and no preload(). Loading every
                     * student on each render is fine at the forty rows a new
                     * centre has and a timeout at ten thousand.
                     *
                     * EnrollStudentAction re-checks the chosen student
                     * server-side: a Select's options are a suggestion and the
                     * submitted value is user input.
                     */
                    ->getSearchResultsUsing(fn (string $search): array => self::searchStudents($search))
                    // Redisplaying an already-chosen value must not re-run the
                    // search; without this the field renders blank after a
                    // validation failure elsewhere on the form.
                    ->getOptionLabelUsing(fn (mixed $value): ?string => self::studentOptionLabel($value)),
            ])
            ->action(function (array $data): void {
                /** @var User $actor */
                $actor = auth()->user();

                /** @var Batch $batch */
                $batch = $this->getOwnerRecord();

                try {
                    /*
                     * ENROLLING RAISES THE BILL (P2-T03, design section 12).
                     *
                     * This called EnrollStudentAction directly until phase 2,
                     * which turned it into the one UI path that creates an
                     * enrolment carrying no charge — silently, on the screen
                     * staff use most. EnrollAndBillAction wraps both writes in
                     * one transaction, and ActionBoundaryArchTest asserts that
                     * nothing under app/ reaches around it.
                     *
                     * No discount is offered here, which is not an omission.
                     * Design section 3 puts the discount on the enrol-and-collect
                     * flow, where an actor holding apply_discount chooses one
                     * against a preview; this screen bills at full price for
                     * everybody, exactly as it did before.
                     */
                    app(EnrollAndBillAction::class)->execute($actor, new EnrollAndBillData(
                        studentId: (int) $data['student_id'],
                        // FROM THE OWNER RECORD, NEVER THE PAYLOAD. A crafted
                        // submission naming another batch cannot redirect the write.
                        batchId: (int) $batch->getKey(),
                    ));
                } catch (BatchClosedException|DuplicateEnrollmentException
                    |StudentNotEnrollableException|AuthorizationException $exception) {
                        self::refuse($exception);

                        return;
                    }

                /*
                 * Over-capacity WARNS and never blocks — spec line 217. The
                 * enrolment has already committed; this tells the reader the batch
                 * is now over its stated ceiling so they can act on it, rather than
                 * refusing a decision the centre is entitled to make.
                 */
                if ($batch->fresh()?->isOverCapacity()) {
                    Notification::make()
                        ->title(__('enrollment.over_capacity_warning'))
                        ->warning()
                        ->persistent()
                        ->send();
                }
            });
    }

    /**
     * Hidden on a row that is already withdrawn: the Action is idempotent, so
     * pressing it would silently do nothing, and a button that does nothing is
     * worse than one that is not there. A completed row keeps the button and gets
     * the typed refusal, because that refusal is worth stating.
     */
    private function withdrawAction(): Action
    {
        return Action::make('withdraw')
            ->label(__('enrollment.withdraw'))
            ->icon(Heroicon::OutlinedUserMinus)
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('enrollment.withdraw'))
            ->hidden(fn (Enrollment $record): bool => $record->status === EnrollmentStatus::Withdrawn)
            // authorize() rather than visible(): visible() is a UX affordance a
            // crafted Livewire mount ignores, whereas authorize() is checked on
            // the server. The Action re-authorizes under lock regardless, which
            // is the real boundary.
            ->authorize(fn (): bool => $this->mayAmendThisBatch())
            ->action(function (Enrollment $record): void {
                /** @var User $actor */
                $actor = auth()->user();

                try {
                    app(WithdrawEnrollmentAction::class)->execute($actor, $record);
                } catch (EnrollmentNotWithdrawableException|AuthorizationException $exception) {
                    self::refuse($exception);
                }
            });
    }

    /**
     * Remove the record entirely — a separate grant from withdrawing it.
     *
     * Not Filament's DeleteAction, which persists by calling $record->delete()
     * and reaches around DeleteEnrollmentAction.
     */
    private function deleteAction(): Action
    {
        return Action::make('delete')
            ->label(__('enrollment.delete_enrollment'))
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription(__('enrollment.delete_enrollment_warning'))
            ->authorize(fn (Enrollment $record): bool => Gate::allows('delete', $record))
            ->action(function (Enrollment $record): void {
                /** @var User $actor */
                $actor = auth()->user();

                try {
                    app(DeleteEnrollmentAction::class)->execute($actor, $record);
                } catch (
                    ChargeAlreadyCommittedException|EnrollmentHasCertificateException
                    |AuthorizationException $exception
                ) {
                    /*
                     * A deletion can be refused for a reason that is not about
                     * permission at all: the bill carries a payment, a
                     * correction or a write-off, or the certificate register
                     * carries an issued document. Catching it here is what turns
                     * that into a readable notification rather than a 500 —
                     * design section 12 keeps an enrolment recorded in error
                     * deletable, and this is the boundary where "in error" stops
                     * being true.
                     */
                    self::refuse($exception);
                }
            });
    }

    /**
     * Tick the students who finished, confirm once — the only bulk action this
     * table has, and the one place completion is marked (spec section 5.3).
     *
     * RUNS THE ACTION ONCE PER SELECTED ROW, DELIBERATELY.
     * -------------------------------------------------------
     * There is no "complete the whole batch" button and never will be: the
     * student who dropped out in week three and was never withdrawn would be
     * certified by default if completion were decided in aggregate. Each row
     * gets its own EnrollmentMutex lock, its own CompletionRule check under
     * that lock, and — because the automatic model-event log fires per
     * $record->update() call inside CompleteEnrollmentAction — its own
     * activity-log entry. Three ticked rows produce three log entries, never
     * one describing all three (see CompletionConcurrencyTest and
     * EnrollmentsRelationManagerTest for the counted proof).
     *
     * ->authorize() HERE IS VISIBILITY, NOT THE BOUNDARY.
     * -------------------------------------------------------
     * mayCompleteThisBatch() is a single, cheap, non-locking read that decides
     * whether the button renders — see its own docblock. A crafted Livewire
     * payload that skips the button entirely still has to pass CompletionRule's
     * locking check inside CompleteEnrollmentAction for every single row, so
     * bypassing the visibility check buys an attacker nothing.
     *
     * PARTIAL FAILURE IS EXPECTED, NOT EXCEPTIONAL. A batch of ticked rows can
     * legitimately mix a genuinely-active enrolment with one somebody already
     * completed from another tab, or one that just got withdrawn. Each row's
     * own refusal is reported through reportBulkProcessingFailure() rather than
     * aborting the whole selection, so the rows that ARE completable still are.
     */
    private function completeAction(): BulkAction
    {
        return BulkAction::make('complete')
            ->label(__('enrollment.complete_enrollment'))
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading(__('enrollment.complete_enrollment'))
            ->authorize(fn (): bool => $this->mayCompleteThisBatch())
            ->action(function (BulkAction $action, Collection $records): void {
                /** @var User $actor */
                $actor = auth()->user();

                /** @var Enrollment $record */
                foreach ($records as $record) {
                    try {
                        app(CompleteEnrollmentAction::class)->execute($actor, $record);
                    } catch (EnrollmentNotCompletableException $exception) {
                        $action->reportBulkProcessingFailure(
                            'not_completable',
                            message: fn (): string => $exception->getMessage(),
                        );
                    } catch (AuthorizationException) {
                        $action->reportBulkProcessingFailure(
                            'unauthorized',
                            message: fn (): string => __('enrollment.enrollment_change_denied'),
                        );
                    } catch (EnrollmentBatchChangedException $exception) {
                        /*
                         * Both Actions declare this, and nothing re-parents an
                         * enrolment today — so it cannot fire yet. It is caught
                         * anyway because of what an ESCAPE would do here.
                         *
                         * Filament does not wrap a bulk action in a transaction
                         * (CanUseDatabaseTransactions defaults the flag false and
                         * this action never opts in), so every row before this
                         * one has already committed. An uncaught throw mid-loop
                         * would leave earlier rows completed, later rows
                         * untouched and a 500 in place of the partial-failure
                         * report — while this method's docblock promises partial
                         * failure is expected, not exceptional.
                         */
                        $action->reportBulkProcessingFailure(
                            'batch_changed',
                            message: fn (): string => $exception->getMessage(),
                        );
                    }
                }
            })
            ->successNotificationTitle(__('enrollment.complete_enrollment_success'))
            ->failureNotificationTitle(fn (int $successCount, int $totalCount): string => __(
                'enrollment.complete_enrollment_partial_failure',
                ['completed' => $successCount, 'selected' => $totalCount],
            ))
            ->deselectRecordsAfterCompletion();
    }

    /**
     * Turn a refusal into a notification the reader can actually read.
     *
     * The domain exceptions carry __() messages already. AuthorizationException
     * does NOT — its message is Laravel's hardcoded English, which would appear
     * verbatim in an Arabic panel from phase 4 — so it is mapped to a key of this
     * domain's own. See InstructorsRelationManager::refuse().
     */
    private static function refuse(
        BatchClosedException|DuplicateEnrollmentException|StudentNotEnrollableException
        |EnrollmentNotWithdrawableException|ChargeAlreadyCommittedException
        |EnrollmentHasCertificateException
        |AuthorizationException $exception,
    ): void {
        Notification::make()
            ->title($exception instanceof AuthorizationException
                ? __('enrollment.enrollment_change_denied')
                : $exception->getMessage())
            ->danger()
            ->persistent()
            ->send();
    }
}
