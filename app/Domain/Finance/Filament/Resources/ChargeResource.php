<?php

declare(strict_types=1);

namespace App\Domain\Finance\Filament\Resources;

use App\Domain\Enrollment\Filament\Resources\BatchResource;
use App\Domain\Enrollment\Filament\Resources\StudentResource;
use App\Domain\Enrollment\Models\Student;
use App\Domain\Enrollment\Services\EnrollmentQueryService;
use App\Domain\Finance\Actions\AdjustChargeAction;
use App\Domain\Finance\Actions\WriteOffChargeAction;
use App\Domain\Finance\Data\AdjustChargeData;
use App\Domain\Finance\Data\WriteOffChargeData;
use App\Domain\Finance\Exceptions\ChargeAlreadyWrittenOffException;
use App\Domain\Finance\Exceptions\ChargeAmountBelowAllocatedException;
use App\Domain\Finance\Filament\Pages\EnrollAndCollect;
use App\Domain\Finance\Filament\Resources\ChargeResource\Pages\ListCharges;
use App\Domain\Finance\Filament\Resources\ChargeResource\Pages\ViewCharge;
use App\Domain\Finance\Models\Charge;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Services\ChargeQueryService;
use App\Domain\Finance\Support\ChargeBalance;
use App\Domain\Finance\Support\Money;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;

/**
 * Bills — read-plus-two-actions, never created or edited through the panel
 * (P2-T05).
 *
 * WHY THERE IS NO CREATE PAGE AND NO EDIT PAGE
 * ---------------------------------------------
 * Design section 4: a charge comes into existence only as a system consequence
 * of `create_enrollment`, through `EnrollAndBillAction` and the internal
 * `IssueChargeAction`. `ChargePolicy::create()` and `update()` return **false
 * unconditionally**, and there is no `create_charge` or `update_charge`
 * permission for either to check — Spatie throws `PermissionDoesNotExist` for
 * an unknown name, which is exactly why the policy answers with a bare `false`
 * rather than a permission check that could never succeed.
 *
 * `docs/ENGINEERING.md` is explicit that `->url()` on a `CreateAction` or
 * `EditAction` does not remove the server-side handler those classes keep
 * mountable — only NOT REGISTERING one closes that door. So `getPages()` below
 * registers exactly two routes, `index` and `view`, and neither `CreateCharge`
 * nor `EditCharge` exists anywhere in this namespace. `canCreate()` is
 * additionally overridden to `false`, belt and braces with the policy, the same
 * defensive redundancy `ActivityResource::canCreate()` uses for the identical
 * reason.
 *
 * TWO ACTIONS, AUTHORIZED, NOT MERELY HIDDEN
 * -------------------------------------------
 * `adjustAction()` and `writeOffAction()` below call `AdjustChargeAction` and
 * `WriteOffChargeAction` directly and are gated with `->authorize()`, never
 * `->visible()` alone — `docs/ENGINEERING.md`'s note that `visible()` is a UX
 * affordance a crafted Livewire mount ignores, while `authorize()` runs
 * `ChargePolicy::adjust()` / `writeOff()` on the server and makes the action
 * unmountable to begin with. Both Actions authorize themselves again
 * independently the moment they run (see their own docblocks); the Filament
 * gate exists so an unauthorized actor never sees a working button, not because
 * it is the only thing standing in the way.
 *
 * Both are shared static builders, exactly as `BatchResource::deleteAction()`
 * is, so the table row and the detail page cannot drift apart on what "adjust"
 * or "write off" does.
 *
 * A THIRD ROW ACTION IS ONLY A LINK
 * -----------------------------------
 * `collectPaymentAction()` ("Collect payment", P4-T32) writes nothing: it links
 * to the Enrol & Collect page with the bill in the URL, and that page re-checks
 * the bill and `create_payment` on the server before it opens anything. It is
 * hidden unless the bill is collectable (not written off, something still
 * outstanding, P4-T36) and the actor may collect.
 *
 * OUTSTANDING GOES THROUGH ChargeBalance'S SQL SHAPE, NOT ITS PHP ONE
 * ----------------------------------------------------------------------
 * `getEloquentQuery()` selects `ChargeBalance::outstandingSql()` into
 * `ChargeBalance::OUTSTANDING_ALIAS` alongside `charges.*`, so the table can
 * sort and filter the balance in the database instead of hydrating every row to
 * compute it in PHP. `ChargeBalance`'s own docblock states — after a review
 * corrected an earlier, false claim — that the expression does **not** compose
 * into a bare `having()`: MySQL resolves `HAVING` against the select list, not
 * the table, so a grouped query without the alias in scope raises "Unknown
 * column 'charges.amount' in 'having clause'". No `GROUP BY` is needed either
 * way, because the correlated subquery never multiplies rows.
 *
 * SORTING USES THE ALIAS; FILTERING CANNOT
 * ----------------------------------------------------------------------
 * `ORDER BY` resolves an alias, so the outstanding column sorts on it. The
 * filter cannot, and an earlier version of this file said it could — citing
 * `ChargeBalanceTest`'s having-on-the-alias case, which is real but proves that
 * shape only against a bare `DB::table()` query.
 *
 * Filament runs every filter's `apply()` inside `$query->where(fn ($query) => …)`,
 * and Laravel's `addNestedWhereQuery()` merges only the nested builder's
 * `wheres` back — the `havings` are dropped with no error and no warning. The
 * filter emitted valid SQL with no `HAVING` clause and returned every row,
 * filtered or not. The filter below therefore uses `whereRaw`; see its comment.
 *
 * The lesson generalises past this file: a SQL fragment proven against the
 * query builder is not thereby proven through a framework pipeline that rebuilds
 * the query around it.
 *
 * WHY `enrollment.student` NEVER APPEARS BELOW
 * -----------------------------------------------
 * `Charge::enrollment()`'s own docblock is explicit: the relation is declared
 * for a display join and for the receipt's enrolment reference, and "reaching
 * the student through it is a domain-boundary violation with a passing test
 * suite" — meaning no architecture test catches it today, but it is still
 * wrong. `EnrollmentQueryService` (design section 12, task 3) is the sanctioned
 * way to resolve a charge's student, and it does not exist in this task's file
 * scope. So this resource shows the enrolment reference and the batch/course
 * path — the walk design section 12 names for reports — and stops there. A
 * later task wiring `EnrollmentQueryService` in is free to add a student column
 * without this resource having built the violation first.
 *
 * MONEY NEVER RENDERS AS A BARE DECIMAL STRING
 * -----------------------------------------------
 * Design section 12: composite strings, including money formatting, get their
 * own translation key rather than being assembled by joining fragments in code,
 * because the separator and the ordering are both localisable.
 * `formatMoney()` is the one place that assembly happens.
 *
 * The class name is written out in full in the `@extends` tag deliberately —
 * Pint's phpdoc_types fixer lowercases a bare `Resource` into PHP's `resource`
 * pseudo-type, which silently turns the tag into a reference to nothing. See
 * `BatchResource`'s identical note.
 *
 * @extends \Filament\Resources\Resource<Charge>
 */
class ChargeResource extends Resource
{
    protected static ?string $model = Charge::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static ?string $recordTitleAttribute = 'reference';

    public static function getModelLabel(): string
    {
        return __('charges.charge');
    }

    public static function getPluralModelLabel(): string
    {
        return __('charges.charges');
    }

    public static function getNavigationLabel(): string
    {
        return __('charges.charges');
    }

    /**
     * Belt and braces with `ChargePolicy::create()`, which already returns
     * `false` unconditionally (design section 4). Filament resolves a missing
     * override to the policy check alone; stating it here as well is the same
     * defensive redundancy `ActivityResource::canCreate()` uses, and it costs
     * nothing since no create route exists to authorize anyway.
     */
    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * Select the outstanding balance alongside every charge column, and
     * eager-load the enrolment's batch and course for the display columns
     * below — without it the list is a textbook N+1, one query per row for
     * `enrollment.batch.course.code`.
     *
     * `select('charges.*')` is deliberate rather than incidental, and NOT
     * because of `having()` — there is no `having()` anywhere in this file;
     * the class docblock explains why the alias cannot compose into one
     * through Filament's filter pipeline. The real reason: `selectRaw()`
     * REPLACES Eloquent's implicit `select *` rather than adding to it —
     * once any column is named, the builder stops defaulting to `*`.
     * Confirmed with `DB::listen()`: dropping `select('charges.*')` here
     * turns the emitted SQL from
     * `select \`charges\`.*, (...) as outstanding_amount from \`charges\``
     * into `select (...) as outstanding_amount from \`charges\`` — every
     * charge column gone except the alias itself. No `GROUP BY` — the
     * balance is a correlated subquery, not a join, so it never multiplies a
     * charge into more than one row.
     *
     * THE FLAG BEHIND "Collect payment" IS SELECTED HERE TOO. Whether a bill is
     * collectable is one SQL condition in ChargeQueryService,
     * selected into `ChargeQueryService::COLLECTABLE_ALIAS` so the
     * row action reads an attribute rather than asking the database per row.
     *
     * NOT `enrollment.student` — see the class docblock.
     *
     * @return Builder<Charge>
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()
            ->select('charges.*')
            ->selectRaw(ChargeBalance::outstandingSql().' as '.ChargeBalance::OUTSTANDING_ALIAS)
            ->selectRaw(ChargeBalance::allocatedSql().' as '.ChargeBalance::ALLOCATED_ALIAS)
            ->selectRaw(app(ChargeQueryService::class)->collectableSql().' as '.ChargeQueryService::COLLECTABLE_ALIAS)
            ->with(['enrollment.batch.course']);

        app(EnrollmentQueryService::class)->joinEnrollmentStudentLabelsTo($query->getQuery(), 'charges.enrollment_id');

        return $query;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->extraAttributes(['class' => '[&_.fi-ta-record-content-ctn]:py-0! [&_.fi-ta-record-content]:px-0!'])
            // Native layout rows wrap their content in a link when recordUrl is
            // set; child student/batch links need a non-interactive container.
            ->recordUrl(null)
            ->columns([
                Split::make([
                    Stack::make([
                        TextColumn::make('student_identity')
                            ->label(__('charges.student'))
                            ->state(fn (Charge $record): string => trim($record->getAttribute(EnrollmentQueryService::ENROLLMENT_STUDENT_FIRST_NAME).' '.$record->getAttribute(EnrollmentQueryService::ENROLLMENT_STUDENT_LAST_NAME)))
                            ->weight('semibold')->size('sm')
                            ->url(fn (Charge $record): ?string => auth()->user()?->can('view_student') && auth()->user()->can('view_any_student')
                                && (bool) $record->getAttribute(EnrollmentQueryService::ENROLLMENT_STUDENT_AVAILABLE)
                                ? StudentResource::getUrl('index', ['student' => $record->getAttribute(EnrollmentQueryService::ENROLLMENT_STUDENT_ID)]) : null),
                        TextColumn::make('reference')
                            ->label(__('charges.reference'))
                            ->searchable()
                            ->sortable()->color('gray')->size('xs')
                            ->url(fn (Charge $record): ?string => auth()->user()?->can('view_charge')
                                ? self::getUrl('view', ['record' => $record]) : null),

                        TextColumn::make('enrollment.reference')
                            ->label(__('charges.enrollment_reference'))
                            ->searchable()
                            ->sortable()->size('xs')->color('gray'),

                        TextColumn::make('enrollment.batch.course.code')
                            ->label(__('charges.course'))
                            ->searchable()->size('xs')->color('gray'),

                        TextColumn::make('enrollment.batch.code')
                            ->label(__('charges.batch'))
                            ->searchable()->size('xs')->color('gray')
                            ->url(fn (Charge $record): ?string => auth()->user()?->can('view_batch')
                                ? BatchResource::getUrl('view', ['record' => $record->enrollment->batch]) : null),
                    ])->space(1),
                    Stack::make([

                        TextColumn::make('amount')
                            ->label(__('charges.amount'))
                            ->formatStateUsing(fn (string $state): string => self::formatMoney($state))
                            ->sortable()->description(__('charges.billed')),

                        TextColumn::make('discount_percentage')
                            ->label(__('charges.discount'))
                            ->formatStateUsing(fn (?string $state): string => $state === null
                                ? __('charges.no_discount')
                                : __('charges.discount_percentage_value', ['percentage' => $state]))
                            ->sortable()->size('xs')->color('gray'),
                    ])->space(1),
                    Stack::make([
                        TextColumn::make('paid_progress')->label(__('charges.allocated'))
                            ->extraAttributes(['class' => '[&_svg]:text-success-400! [&_.font-semibold]:text-success-400!'])
                            ->state(fn (Charge $record): string => (string) $record->getAttribute(ChargeBalance::ALLOCATED_ALIAS))
                            ->formatStateUsing(fn (string $state, Charge $record): HtmlString => new HtmlString(Blade::render(
                                '<x-admin.meter :value="$paid" :maximum="$billed" :label="$label" tone="success" :percentage="true" />',
                                ['paid' => Money::fromDecimal($state)->dirham, 'billed' => Money::fromDecimal($record->amount)->dirham,
                                    'label' => __('charges.paid_amount', ['amount' => self::formatMoney($state)])],
                            ))),

                        // The SQL shape, not the PHP one — see the class docblock. The
                        // alias is exactly what getEloquentQuery() selected it as, so a
                        // typo here would silently render an empty column rather than
                        // fail, the same reasoning ChargeBalance::OUTSTANDING_ALIAS
                        // documents.
                        TextColumn::make(ChargeBalance::OUTSTANDING_ALIAS)
                            ->label(__('charges.outstanding'))
                            ->formatStateUsing(fn (string $state): string => self::formatMoney($state))
                            ->sortable()->color('warning')
                            ->description(fn (Charge $record): ?string => auth()->user()?->can('view_any_payment') && auth()->user()->can('view_student')
                                && (bool) $record->getAttribute(EnrollmentQueryService::ENROLLMENT_STUDENT_AVAILABLE)
                                ? __('charges.view_payments') : null)
                            ->url(fn (Charge $record): ?string => auth()->user()?->can('view_any_payment') && auth()->user()->can('view_student')
                                && (bool) $record->getAttribute(EnrollmentQueryService::ENROLLMENT_STUDENT_AVAILABLE)
                                ? PaymentResource::getUrl('index', ['filters' => ['student' => ['id' => $record->getAttribute(EnrollmentQueryService::ENROLLMENT_STUDENT_ID)]]]) : null),
                    ])->space(1),
                    Stack::make([
                        TextColumn::make('payment_state')->label(__('charges.payment_state'))
                            ->state(fn (Charge $record): string => $record->isWrittenOff() ? __('charges.written_off')
                                : (Money::fromDecimal((string) $record->getAttribute(ChargeBalance::OUTSTANDING_ALIAS))->isZero()
                                    ? __('charges.settled') : (Money::fromDecimal((string) $record->getAttribute(ChargeBalance::ALLOCATED_ALIAS))->isPositive()
                                        ? __('charges.part_paid') : __('charges.unpaid'))))
                            ->badge()->color(fn (Charge $record): string => $record->isWrittenOff() ? 'gray'
                                : (Money::fromDecimal((string) $record->getAttribute(ChargeBalance::OUTSTANDING_ALIAS))->isZero() ? 'success' : 'warning')),

                        TextColumn::make('due_date')
                            ->label(__('charges.due_date'))
                            ->date()
                            ->sortable()->size('xs')->color('gray'),

                        /*
                 * Derived from the fact column, never stored — Charge::isWrittenOff()
                 * is the same predicate the model exposes, read here rather than
                 * redefined. NOT a status column and not meant to read as one:
                 * design section 4 forbids a status column throughout Finance, and
                 * writing off does not change what is owed. The tooltip carries the
                 * reason so the debt's history stays visible from the list, not just
                 * the detail page, matching design section 4's "nothing is erased".
                 */
                        IconColumn::make('written_off')
                            ->label(__('charges.written_off'))
                            ->state(fn (Charge $record): bool => $record->isWrittenOff())
                            ->boolean()
                            ->falseIcon(false)
                            ->tooltip(fn (Charge $record): ?string => $record->isWrittenOff()
                                ? $record->written_off_reason
                                : null),
                    ])->space(1),
                ])->from('lg')->extraAttributes(['class' => 'gap-3.5 py-3.5 px-4.5']),
            ])
            ->filters([
                Filter::make('student')->label(__('enrollment.student'))
                    ->schema([TextInput::make('id')->label(__('enrollment.student_id'))->integer()->minValue(1)])
                    ->query(function (Builder $query, array $data): Builder {
                        if (blank($data['id'] ?? null)) {
                            return $query;
                        }
                        $id = filter_var($data['id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                        $student = $id === false ? null : Student::query()->find($id);
                        if ($student === null || ! Gate::allows('view', $student)) {
                            return $query->whereRaw('1 = 0');
                        }
                        $ids = app(EnrollmentQueryService::class)->scopeToStudent(
                            DB::table('charges as selected_charges')->select('selected_charges.id'),
                            'selected_charges.enrollment_id', (int) $id,
                        );

                        return $query->whereIn('charges.id', $ids);
                    }),
                /*
                 * whereRaw, NOT having() — and this cost a real defect to learn.
                 *
                 * ChargeBalanceTest proves that selecting the alias and calling
                 * having() on its NAME works, and it does — against a bare
                 * DB::table() query. It does not survive Filament's filter
                 * pipeline. HasFilters::applyFiltersToTableQuery() runs every
                 * filter's apply() inside $query->where(function ($query) {…}),
                 * and Laravel's Builder::addNestedWhereQuery() merges only the
                 * nested builder's `wheres` back into the parent. Its `havings`
                 * are silently dropped.
                 *
                 * The failure mode is the dangerous one: no error, no warning,
                 * and SQL that is valid — just without the HAVING clause. The
                 * filter appeared to work and returned every row, filtered or
                 * not. Confirmed by reading the emitted SQL through DB::listen()
                 * rather than by inference.
                 *
                 * whereRaw repeats the expression instead of naming the alias,
                 * which costs the subquery twice on a filtered query. That is
                 * the price of a filter that actually filters, and the ORDER BY
                 * above still uses the alias, where MySQL does resolve it.
                 *
                 * ChargeResourceTest asserts the returned ROWS, not that the
                 * query runs. A test that only asserted it ran would have passed
                 * against the broken version — which is precisely what happened.
                 */
                Filter::make('outstanding_only')
                    ->label(__('charges.filter_outstanding'))
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query
                        ->whereRaw(ChargeBalance::outstandingSql().' > 0')),

                // TernaryFilter::nullable() wires whereNotNull()/whereNull() on
                // the filter's own name, which is a real column here.
                TernaryFilter::make('written_off_at')
                    ->label(__('charges.written_off'))
                    ->trueLabel(__('charges.filter_written_off_true'))
                    ->falseLabel(__('charges.filter_written_off_false'))
                    ->nullable(),
            ])
            ->defaultSort('due_date', 'desc')
            // authorize() on each action, not visible() — see the class
            // docblock. Shared builders so the table row and ViewCharge's
            // header actions cannot drift apart.
            ->recordActions([
                self::collectPaymentAction(),
                self::adjustAction(),
                self::writeOffAction(),
            ])
            // No bulk actions of any kind. ChargePolicy's *Any methods refuse
            // unconditionally (design section 10; see docs/ENGINEERING.md's
            // "Bulk actions cannot be authorized per record" and RolePolicy's
            // identical reasoning) — stated explicitly rather than left absent,
            // the same defensive style ActivityResource::table() uses.
            ->toolbarActions([]);
    }

    /**
     * Collect a payment on a bill later (P4-T32, QA Q1; P4-T36 for an installment): a
     * link to the Enrol & Collect page with this bill in the URL, which opens its
     * collection panel, pre-filled with what is still owed.
     *
     * WHICH BILLS: those that are COLLECTABLE — the one condition in
     * ChargeQueryService::collectableSql(), not written off and something still
     * outstanding, read here from the attribute `getEloquentQuery()` selected it
     * into, so a row costs no query. A payment already on the bill does not hide the
     * button: a student who paid 300 of 850 comes back for the other 550, and that is
     * a new payment with its own receipt on the same bill.
     *
     * HIDDEN, NOT THE GUARD. `visible()` is a courtesy; the page re-checks the
     * condition and `create_payment` on the server when it opens, and
     * `RecordPaymentAction` authorizes again when it records. The button also
     * asks `EnrollAndCollect::canAccess()`, because a link to a page the actor
     * would be refused is worse than no link.
     */
    public static function collectPaymentAction(): Action
    {
        return Action::make('collectPayment')
            ->label(__('charges.collect_payment'))
            ->icon(Heroicon::OutlinedBanknotes)
            ->color('success')
            ->url(fn (Charge $record): string => EnrollAndCollect::getUrl(['charge' => $record->getKey()]))
            ->visible(fn (Charge $record): bool => (bool) $record->getAttribute(ChargeQueryService::COLLECTABLE_ALIAS)
                && Gate::allows('create', Payment::class)
                && Gate::allows('view', $record)
                && EnrollAndCollect::canAccess());
    }

    /**
     * Correct a data-entry error on this charge's amount — never a late
     * discount (design section 4). Gated on `adjust_charge` via
     * `ChargePolicy::adjust()`.
     *
     * THE REASON IS MANDATORY IN THE FORM, NOT JUST IN THE DTO
     * -------------------------------------------------------------
     * `AdjustChargeData`'s own constructor already refuses a blank reason, but
     * that guard exists for a hand-built payload, not as the user-facing
     * validation — the field is `->required()` here so a blank submission never
     * leaves the browser.
     *
     * THE AMOUNT'S PRECISION IS VALIDATED HERE, BEFORE `Money::fromDecimal()`
     * EVER SEES IT
     * -----------------------------------------------------------------------------
     * `Money`'s own docblock: a fourth decimal place must be REJECTED, never
     * rounded and reported as unchanged. `Money::fromDecimal()` already refuses
     * one with an `InvalidArgumentException`, but that exception is a developer
     * diagnostic, not a translated user-facing message (see `Money`'s own
     * docblock) — the regex below is what turns a mistyped amount into a
     * readable field error instead of an uncaught exception reaching the panel.
     *
     * ONLY THE ALLOCATED-TOTAL REFUSAL IS CAUGHT
     * -----------------------------------------------
     * `ChargeAmountBelowAllocatedException` is the one typed exception this
     * form's happy path can actually trigger; anything else — a stale record, an
     * authorization race — propagates as a genuine fault rather than being
     * dressed up as a polite notification.
     */
    public static function adjustAction(): Action
    {
        return Action::make('adjust')
            ->label(__('charges.adjust'))
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('warning')
            ->authorize('adjust')
            ->modalHeading(__('charges.adjust_modal_heading'))
            ->schema([
                TextInput::make('amount')
                    ->label(__('charges.new_amount'))
                    ->helperText(__('charges.new_amount_hint'))
                    ->default(fn (Charge $record): string => $record->amount)
                    ->required()
                    /*
                     * NO ->numeric() ON A MONEY FIELD. EVER.
                     * -----------------------------------------
                     * It installs Filament's NumberStateCast, whose get() and
                     * set() both run floatval(), so the amount would be a float
                     * on both sides of this form — hydrated out of a
                     * `decimal(12,3)` column into one, and handed to the Action
                     * as one. Design section 6 and CLAUDE.md's third
                     * non-negotiable forbid exactly that: a float cannot
                     * represent 0.001, and the dirham is the digit it loses.
                     *
                     * It also defeats the arrangement this task is built on.
                     * `Money::fromDecimal()` inside `AdjustChargeData` is meant
                     * to be the FIRST thing that parses the operator's
                     * keystrokes; behind a float cast it re-parses a string
                     * that PHP has already re-serialised out of a double.
                     *
                     * An `afterStateHydrated()` reformat does not rescue it —
                     * that only changes what is DISPLAYED. `getState()`, which
                     * is what dehydrates into `$data` below, runs the cast
                     * regardless. `ChargeResourceTest` asserts getState() is the
                     * exact string, and asserts this field is not numeric, so
                     * re-adding the call fails there rather than silently.
                     *
                     * What is lost by dropping it, and how each is replaced:
                     * the mobile decimal keypad, restored by inputMode() below
                     * — that is all ->numeric() was doing for it; and the
                     * `numeric` validation rule, which the regex already
                     * subsumes and improves on, since `numeric` accepts "1e3"
                     * and a fourth decimal place while the regex does not.
                     *
                     * The T2 price fields keep ->numeric() and are safe by a
                     * different route: they are dehydrated(false) and their
                     * Actions read `$this->form->getRawState()`, the uncast
                     * value. A modal action has no equivalent raw-state hook,
                     * so here the cast is simply not installed.
                     */
                    ->inputMode('decimal')
                    // decimal(12,3): up to 9 whole digits, at most 3 decimal
                    // places, and no sign — which is also what makes a
                    // minValue(0) unnecessary here (without ->numeric() a
                    // `min:0` rule would compare string LENGTH, and quietly
                    // guard nothing at all). Money::fromDecimal() enforces the
                    // same shape; this is what turns a violation into a field
                    // error instead of an uncaught exception.
                    ->rule('regex:/^\d{1,9}(\.\d{1,3})?$/')
                    ->validationMessages([
                        'regex' => __('charges.amount_format_error'),
                    ]),

                Textarea::make('reason')
                    ->label(__('charges.reason'))
                    ->required()
                    ->maxLength(1000),
            ])
            ->successNotificationTitle(__('charges.adjusted_successfully'))
            ->action(function (Charge $record, array $data, Action $action): void {
                /** @var User $actor */
                $actor = auth()->user();

                $payload = new AdjustChargeData(
                    (int) $record->getKey(),
                    (string) $data['amount'],
                    (string) $data['reason'],
                );

                try {
                    app(AdjustChargeAction::class)->execute($actor, $payload);
                } catch (ChargeAmountBelowAllocatedException $exception) {
                    Notification::make()
                        ->title($exception->getMessage())
                        ->body(__('charges.amount_below_allocated_detail', [
                            'attempted' => self::formatMoney($exception->attemptedAmount),
                            'allocated' => self::formatMoney($exception->allocatedAmount),
                        ]))
                        ->danger()
                        ->send();

                    $action->halt();
                }
            });
    }

    /**
     * Retire a debt the centre has accepted it will never collect (design
     * section 4). Gated on `write_off_charge` via `ChargePolicy::writeOff()`.
     *
     * NOTHING IS ERASED. `ChargeBalance` deliberately does not subtract a
     * write-off, so this action changes nothing about what is displayed as
     * outstanding — only the aged report (a later task) excludes it.
     *
     * HIDDEN ONCE ALREADY WRITTEN OFF — A UX COURTESY, NOT THE GUARD
     * --------------------------------------------------------------------
     * `WriteOffChargeAction` refuses a second write-off with
     * `ChargeAlreadyWrittenOffException` regardless of what the panel shows;
     * `->visible()` here only stops an actor from opening a form that would
     * always be refused. Removing this line would not open a security hole —
     * `->authorize()` is what does that job — it would only mean occasionally
     * clicking a button that was always going to fail.
     */
    public static function writeOffAction(): Action
    {
        return Action::make('writeOff')
            ->label(__('charges.write_off'))
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->authorize('writeOff')
            ->visible(fn (Charge $record): bool => ! $record->isWrittenOff())
            ->modalHeading(__('charges.write_off_modal_heading'))
            ->schema([
                Textarea::make('reason')
                    ->label(__('charges.reason'))
                    ->helperText(__('charges.write_off_reason_hint'))
                    ->required()
                    ->maxLength(1000),
            ])
            ->successNotificationTitle(__('charges.written_off_successfully'))
            ->action(function (Charge $record, array $data, Action $action): void {
                /** @var User $actor */
                $actor = auth()->user();

                $payload = new WriteOffChargeData(
                    (int) $record->getKey(),
                    (string) $data['reason'],
                );

                try {
                    app(WriteOffChargeAction::class)->execute($actor, $payload);
                } catch (ChargeAlreadyWrittenOffException $exception) {
                    Notification::make()
                        ->title($exception->getMessage())
                        ->danger()
                        ->send();

                    $action->halt();
                }
            });
    }

    /**
     * The one place a dirham amount is assembled into user-facing text.
     *
     * Design section 12: money formatting is a composite string and gets its
     * own translation key rather than being joined in code, because the
     * separator and the ordering are both localisable — Arabic phase 4 may
     * read `:amount` before the currency word rather than after it.
     *
     * Takes the raw decimal string a `decimal:3` cast or `Money::toDecimal()`
     * produces, never a `Money` itself — `Money` deliberately has no string
     * conversion (see its own docblock) so that nothing casts it by accident on
     * the way to a view.
     */
    public static function formatMoney(string $decimal): string
    {
        return __('charges.amount_lyd', ['amount' => $decimal]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCharges::route('/'),
            'view' => ViewCharge::route('/{record}'),
        ];
    }
}
