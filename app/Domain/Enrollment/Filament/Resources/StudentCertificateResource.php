<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Filament\Resources;

use App\Domain\Enrollment\Actions\IssueStudentCertificateAction;
use App\Domain\Enrollment\Actions\ReplaceStudentCertificateAction;
use App\Domain\Enrollment\Actions\RevokeStudentCertificateAction;
use App\Domain\Enrollment\Enums\CertificateStatus;
use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Enrollment\Exceptions\CertificateAlreadyIssuedException;
use App\Domain\Enrollment\Exceptions\EnrollmentNotCompletedException;
use App\Domain\Enrollment\Exceptions\NoValidCertificateException;
use App\Domain\Enrollment\Filament\Resources\StudentCertificateResource\Pages\ListStudentCertificates;
use App\Domain\Enrollment\Filament\Resources\StudentCertificateResource\Pages\ViewStudentCertificate;
use App\Domain\Enrollment\Models\Enrollment;
use App\Domain\Enrollment\Models\StudentCertificate;
use App\Domain\Finance\Services\ChargeQueryService;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Gate;

/**
 * The certificate register — read-plus-three-actions, never created, updated
 * or deleted through the panel (design section 6, P3-T05).
 *
 * NO CREATE PAGE, NO EDIT PAGE — THE SAME SHAPE AS ChargeResource
 * -------------------------------------------------------------------
 * StudentCertificatePolicy::create() and update() refuse unconditionally
 * (T4), and there is no create_student_certificate or
 * update_student_certificate permission for either to check —
 * RolePermissionSeeder deliberately never seeds them. `getPages()` below
 * registers exactly `index` and `view`; no CreateStudentCertificate or
 * EditStudentCertificate class exists anywhere in this namespace, and
 * `canCreate()` says so explicitly, belt and braces with the policy —
 * see docs/ENGINEERING.md's note that `->url()` on a CreateAction/EditAction
 * does not close the server-side handler, only NOT REGISTERING one does.
 *
 * ISSUE IS A HEADER ACTION, NOT A RECORD ACTION
 * -----------------------------------------------
 * Replacing and revoking both act on an EXISTING certificate row, so they are
 * record actions exactly as ChargeResource::adjustAction() and
 * writeOffAction() are. Issuing has no row to attach to yet — its subject is
 * a completed ENROLMENT with no valid certificate — so issueAction() lives on
 * ListStudentCertificates' header instead, with its own enrolment picker.
 *
 * ALL THREE ARE AUTHORIZED, NOT MERELY HIDDEN
 * -----------------------------------------------
 * `->authorize()` below calls StudentCertificatePolicy's issue/replace/revoke
 * methods, never `->visible()` alone (docs/ENGINEERING.md). Each Action
 * authorizes itself again independently the moment it runs — the Filament
 * gate exists so an unauthorized actor never sees a working button, not
 * because it is the only thing standing in the way.
 *
 * AN OUTSTANDING BALANCE IS DISPLAYED, AND CONSULTED BY NOTHING
 * -------------------------------------------------------------------
 * issueAction()'s form shows ChargeQueryService::outstandingForEnrollment()
 * purely for information. IssueStudentCertificateAction has no code path that
 * reads it, and design section 6.4/CLAUDE.md's non-negotiables both require
 * that this stays true — blocking would strand every partial payer
 * permanently, since system design section 12 records that no screen exists
 * to collect a later instalment.
 *
 * The class name is written out in full in the `@extends` tag deliberately —
 * Pint's phpdoc_types fixer lowercases a bare `Resource` into PHP's `resource`
 * pseudo-type, which silently turns the tag into a reference to nothing (see
 * ChargeResource's and BatchResource's identical note).
 *
 * @extends \Filament\Resources\Resource<StudentCertificate>
 */
class StudentCertificateResource extends Resource
{
    protected static ?string $model = StudentCertificate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static ?string $recordTitleAttribute = 'reference_number';

    /** How many enrolments the issue picker offers for one search. A bound, not a preference. */
    private const SEARCH_RESULT_LIMIT = 25;

    public static function getModelLabel(): string
    {
        return __('certificates.certificate');
    }

    public static function getPluralModelLabel(): string
    {
        return __('certificates.certificates');
    }

    public static function getNavigationLabel(): string
    {
        return __('certificates.certificates');
    }

    /**
     * Belt and braces with StudentCertificatePolicy::create(), which already
     * refuses unconditionally. Filament resolves a missing override to the
     * policy check alone; stating it here costs nothing since no create route
     * exists to authorize anyway — see ChargeResource::canCreate()'s
     * identical note.
     */
    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * Eager-load who issued and who revoked, for the two name columns below.
     * `student_name` and `course_name` need no join at all — they are the
     * issuance snapshot, already columns on this very table.
     *
     * @return Builder<StudentCertificate>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['issuedBy', 'revokedBy']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('issued_at', 'desc')
            ->columns([
                TextColumn::make('reference_number')
                    ->label(__('certificates.reference_number'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('student_name')
                    ->label(__('certificates.student'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('course_name')
                    ->label(__('certificates.course'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('completed_on')
                    ->label(__('certificates.completed_on'))
                    ->date()
                    ->sortable(),

                TextColumn::make('issued_at')
                    ->label(__('certificates.issued_at'))
                    ->dateTime()
                    ->sortable(),

                TextColumn::make('issuedBy.name')
                    ->label(__('certificates.issued_by')),

                TextColumn::make('status')
                    ->label(__('certificates.status'))
                    ->badge()
                    ->formatStateUsing(fn (CertificateStatus $state): string => $state->label())
                    ->color(fn (CertificateStatus $state): string => match ($state) {
                        CertificateStatus::Valid => 'success',
                        CertificateStatus::Replaced => 'gray',
                        CertificateStatus::Revoked => 'danger',
                    }),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('certificates.status'))
                    ->options(fn (): array => collect(CertificateStatus::cases())
                        ->mapWithKeys(fn (CertificateStatus $status): array => [$status->value => $status->label()])
                        ->all()),
            ])
            // authorize() on each action, not visible() — see the class
            // docblock. Shared builders so the table row and
            // ViewStudentCertificate's header actions cannot drift apart.
            ->recordActions([
                self::replaceAction(),
                self::revokeAction(),
            ])
            // No bulk actions of any kind. StudentCertificatePolicy's *Any
            // methods refuse unconditionally (T4; see
            // docs/ENGINEERING.md's "Bulk actions cannot be authorized per
            // record") — stated explicitly rather than left absent, the same
            // defensive style ChargeResource::table() uses.
            ->toolbarActions([]);
    }

    /**
     * Issue a certificate for a completed enrolment with no valid certificate
     * standing against it. A header action, not a record one — see the class
     * docblock.
     *
     * THE PICKER OFFERS ONLY GENUINELY ISSUABLE ENROLMENTS, AS A COURTESY
     * -------------------------------------------------------------------------
     * `searchIssuableEnrollments()` filters to `completed` enrolments with no
     * `valid` certificate — but IssueStudentCertificateAction re-checks both
     * facts itself, under lock, regardless of what the picker offered. A
     * crafted submission naming an ineligible enrolment meets the Action's own
     * typed refusal, not a silent success; this filtering only keeps the list
     * usable and keeps an obviously-wrong choice off the screen.
     */
    public static function issueAction(): Action
    {
        return Action::make('issue')
            ->label(__('certificates.issue'))
            ->icon(Heroicon::OutlinedPlusCircle)
            ->authorize(fn (): bool => Gate::allows('issue', StudentCertificate::class))
            ->modalHeading(__('certificates.issue_modal_heading'))
            ->schema([
                Select::make('enrollment_id')
                    ->label(__('certificates.enrollment'))
                    ->required()
                    ->searchable()
                    ->live()
                    ->getSearchResultsUsing(fn (string $search): array => self::searchIssuableEnrollments($search))
                    ->getOptionLabelUsing(fn (mixed $value): ?string => self::enrollmentOptionLabel($value)),

                TextEntry::make('outstanding_balance')
                    ->label(__('certificates.outstanding_balance'))
                    ->helperText(__('certificates.outstanding_balance_hint'))
                    ->state(fn (Get $get): string => self::outstandingBalanceDisplay($get('enrollment_id'))),
            ])
            ->successNotificationTitle(__('certificates.issued_successfully'))
            ->action(function (array $data, Action $action): void {
                /** @var User $actor */
                $actor = auth()->user();

                $enrollment = Enrollment::query()->find((int) $data['enrollment_id']);

                if (! $enrollment instanceof Enrollment) {
                    $action->halt();

                    return;
                }

                try {
                    app(IssueStudentCertificateAction::class)->execute($actor, $enrollment);
                } catch (EnrollmentNotCompletedException|CertificateAlreadyIssuedException $exception) {
                    self::refuse($exception);
                    $action->halt();
                }
            });
    }

    /**
     * Reissue the currently valid certificate, superseding it. Hidden once a
     * row is no longer `valid` — a UX courtesy, not the guard:
     * ReplaceStudentCertificateAction refuses NoValidCertificateException
     * regardless of what the panel shows; ->authorize() is what actually
     * closes the door.
     */
    public static function replaceAction(): Action
    {
        return Action::make('replace')
            ->label(__('certificates.replace'))
            ->icon(Heroicon::OutlinedArrowPath)
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading(__('certificates.replace_modal_heading'))
            ->modalDescription(__('certificates.replace_modal_description'))
            ->authorize('replace')
            ->visible(fn (StudentCertificate $record): bool => $record->status === CertificateStatus::Valid)
            ->successNotificationTitle(__('certificates.replaced_successfully'))
            ->action(function (StudentCertificate $record, Action $action): void {
                /** @var User $actor */
                $actor = auth()->user();

                try {
                    app(ReplaceStudentCertificateAction::class)->execute($actor, $record->enrollment);
                } catch (NoValidCertificateException $exception) {
                    self::refuse($exception);
                    $action->halt();
                }
            });
    }

    /**
     * Revoke the currently valid certificate. Hidden once a row is no longer
     * `valid`, the same courtesy replaceAction() gives — see its docblock.
     */
    public static function revokeAction(): Action
    {
        return Action::make('revoke')
            ->label(__('certificates.revoke'))
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->authorize('revoke')
            ->visible(fn (StudentCertificate $record): bool => $record->status === CertificateStatus::Valid)
            ->modalHeading(__('certificates.revoke_modal_heading'))
            ->schema([
                Textarea::make('reason')
                    ->label(__('certificates.reason'))
                    ->helperText(__('certificates.revoke_reason_hint'))
                    ->required()
                    ->maxLength(1000),
            ])
            ->successNotificationTitle(__('certificates.revoked_successfully'))
            ->action(function (StudentCertificate $record, array $data, Action $action): void {
                /** @var User $actor */
                $actor = auth()->user();

                try {
                    app(RevokeStudentCertificateAction::class)->execute(
                        $actor,
                        $record->enrollment,
                        (string) $data['reason'],
                    );
                } catch (NoValidCertificateException $exception) {
                    self::refuse($exception);
                    $action->halt();
                }
            });
    }

    /**
     * The bounded, server-side search behind the issue picker.
     *
     * Public and static so it can be tested as itself — reaching it through
     * the mounted action's schema means asserting against Filament's
     * component internals, matching EnrollmentsRelationManager::searchStudents()'s
     * own reasoning.
     *
     * Completed, with no valid certificate already standing — the two facts
     * IssueStudentCertificateAction itself checks under lock. Filtering here
     * is a courtesy only; see issueAction()'s docblock.
     *
     * @return array<int, string> enrolment id => label
     */
    public static function searchIssuableEnrollments(string $search): array
    {
        return Enrollment::query()
            ->where('status', EnrollmentStatus::Completed)
            ->whereNotIn('id', function (QueryBuilder $query): void {
                $query->from('student_certificates')
                    ->where('status', CertificateStatus::Valid->value)
                    ->select('enrollment_id');
            })
            ->whereHas('student', fn (Builder $student): Builder => $student
                ->where('student_code', 'like', $search.'%')
                ->orWhere('first_name', 'like', '%'.$search.'%')
                ->orWhere('last_name', 'like', '%'.$search.'%'))
            ->with(['student', 'batch.course'])
            ->limit(self::SEARCH_RESULT_LIMIT)
            ->get()
            ->mapWithKeys(fn (Enrollment $enrollment): array => [
                (int) $enrollment->getKey() => self::enrollmentLabel($enrollment),
            ])
            ->all();
    }

    /**
     * The label for an already-selected enrolment, redisplayed without
     * re-running the search — the same reason
     * EnrollmentsRelationManager::studentOptionLabel() exists.
     */
    public static function enrollmentOptionLabel(mixed $value): ?string
    {
        $enrollment = Enrollment::query()->with(['student', 'batch.course'])->find($value);

        return $enrollment instanceof Enrollment ? self::enrollmentLabel($enrollment) : null;
    }

    private static function enrollmentLabel(Enrollment $enrollment): string
    {
        return __('certificates.enrollment_option_label', [
            'code' => $enrollment->student->student_code,
            'name' => $enrollment->student->full_name,
            'course' => $enrollment->batch->course->name(),
        ]);
    }

    /**
     * The outstanding-balance figure for the currently selected enrolment,
     * recomputed on every render from `enrollment_id` via `Get` — the same
     * "never stale" reasoning EnrollAndCollect::previewStep() gives for its
     * own preview entries.
     *
     * Purely informational — see the class docblock.
     */
    private static function outstandingBalanceDisplay(mixed $enrollmentId): string
    {
        if (! is_numeric($enrollmentId)) {
            return __('certificates.no_charge');
        }

        $outstanding = app(ChargeQueryService::class)->outstandingForEnrollment((int) $enrollmentId);

        return $outstanding === null
            ? __('certificates.no_charge')
            : __('certificates.outstanding_balance_lyd', ['amount' => $outstanding->toDecimal()]);
    }

    /**
     * Turn a refusal into a notification the reader can actually read.
     *
     * NO AuthorizationException HERE, UNLIKE EnrollmentsRelationManager::refuse().
     * ------------------------------------------------------------------------------
     * Every action above is gated with ->authorize(), which — per
     * docs/ENGINEERING.md — makes the action itself unmountable to an actor who
     * lacks the ability, rather than merely hiding a button a crafted request
     * could still reach. There is no scoped, row-dependent permission on this
     * resource the way there is on enrolment completion (CompletionRule), so
     * there is no path by which an authorized-looking click reaches the
     * underlying Action's own Gate::authorize() and gets refused — matching
     * ChargeResource's adjustAction()/writeOffAction(), which catch only their
     * business-rule exceptions and not AuthorizationException either.
     */
    private static function refuse(
        EnrollmentNotCompletedException|CertificateAlreadyIssuedException|NoValidCertificateException $exception,
    ): void {
        Notification::make()
            ->title($exception->getMessage())
            ->danger()
            ->persistent()
            ->send();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStudentCertificates::route('/'),
            'view' => ViewStudentCertificate::route('/{record}'),
        ];
    }
}
