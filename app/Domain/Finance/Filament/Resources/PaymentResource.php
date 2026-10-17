<?php

declare(strict_types=1);

namespace App\Domain\Finance\Filament\Resources;

use App\Domain\Enrollment\Filament\Resources\StudentResource;
use App\Domain\Enrollment\Models\Student;
use App\Domain\Finance\Actions\ReversePaymentAction;
use App\Domain\Finance\Exceptions\PaymentAlreadyReversedException;
use App\Domain\Finance\Filament\Resources\PaymentResource\Pages\ListPayments;
use App\Domain\Finance\Filament\Resources\PaymentResource\Pages\ViewPayment;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Models\PaymentTender;
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
use Illuminate\Support\Facades\Gate;

/**
 * Payments — listed and viewed, never created or edited through the panel.
 * Two row actions: a receipt download link (P35-T06) and a reversal (P2-T04).
 *
 * WHY THERE IS NO CREATE PAGE AND NO EDIT PAGE
 * ---------------------------------------------
 * Unlike `ChargePolicy::create()`, `PaymentPolicy::create()` is a real,
 * permission-based check — `create_payment` is seeded to both super_admin
 * and admin — so the absence of a create route here is NOT the policy
 * refusing everyone the way `ChargeResource`'s docblock describes. It is a
 * design decision: design section 2 requires a payment to be recorded
 * through the collection flow (task 9), against a bill already open in
 * front of the operator, never by typing a row into a standalone resource
 * with a bill picked from a list. `getPages()` below registers exactly two
 * routes, `index` and `view`, and neither `CreatePayment` nor `EditPayment`
 * exists anywhere in this namespace. `canCreate()` is additionally
 * overridden to `false`, the same belt-and-braces `ChargeResource::canCreate()`
 * and `ActivityResource::canCreate()` already use, even though nothing here
 * is unconditionally refused the way theirs is.
 *
 * TWO ROW ACTIONS, BOTH GATED WITH authorize() — ONLY REVERSE IS GUARDED HERE
 * ---------------------------------------------------------------------------
 * `downloadReceiptAction()` (P35-T06) is a link to the policy-authorized
 * receipt route with no server handler of its own. Its `authorize()` only
 * decides whether the button renders; what protects the bytes is that
 * route — see its docblock. It is a static builder,
 * but `ViewPayment` does not use it: **only `reverseAction()` is shared with
 * that page**, so only "reverse" carries the no-drift guarantee described
 * below.
 *
 * `reverseAction()` below calls `ReversePaymentAction` directly and is
 * gated with `->authorize()`, never `->visible()` alone —
 * `docs/ENGINEERING.md`'s note that `visible()` is a UX affordance a
 * crafted Livewire mount ignores, while `authorize()` runs
 * `PaymentPolicy::reverse()` on the server and makes the action unmountable
 * to begin with. `ReversePaymentAction` authorizes itself again
 * independently the moment it runs (see its own docblock); the Filament
 * gate exists so an unauthorized actor never sees a working button, not
 * because it is the only thing standing in the way.
 *
 * Shared as a static builder, exactly as `ChargeResource::adjustAction()`
 * and `writeOffAction()` are, so the table row and `ViewPayment`'s header
 * action cannot drift apart on what "reverse" does.
 *
 * THE TENDER TOTAL IS A SQL SUM, NEVER A PHP ONE
 * -------------------------------------------------
 * `payments` carries no amount column — `Payment`'s own docblock is
 * explicit that 300 on card and 700 in cash is one payment with two tenders
 * and one `method` column cannot say that. `getEloquentQuery()` below
 * selects `SUM(payment_tenders.amount)` with `withSum('tenders', 'amount')`,
 * a SQL aggregate, and the table column renders that alias directly.
 * Summing `$payment->tenders` in PHP would add `decimal:3` STRINGS, which
 * PHP does as float — losing exactly the dirham this whole domain exists to
 * keep (design section 6; `ChargeBalance`'s own docblock states the
 * identical reasoning for the outstanding balance).
 *
 * `withAggregate()` (which `withSum()` calls) auto-selects `payments.*`
 * itself when no other `select()` has run yet — unlike `selectRaw()`,
 * which `ChargeResource::getEloquentQuery()`'s own docblock warns REPLACES
 * Eloquent's implicit `select *` the moment it is called. `withSum()` is
 * the only thing this query selects, so that auto-select fires and no
 * explicit `select('payments.*')` is needed the way `ChargeResource`
 * needs one.
 *
 * WHY THIS RESOURCE READS `student.full_name` AND `recordedBy.name`
 * DIRECTLY, UNLIKE `ChargeResource`'s WARNING ABOUT `enrollment.student`
 * ------------------------------------------------------------------------
 * `Charge::enrollment()`'s docblock forbids walking `enrollment.student`
 * because `Charge` has no `student_id` column of its own — that walk
 * reaches across a relation whose only job is a display join. `Payment`
 * is different: `student_id` is a plain foreign key column on this table,
 * resolved once by `RecordPaymentAction` from the locked charge (see
 * `Payment`'s own docblock) and stored directly on the row this resource
 * queries. Reading `$payment->student` is not a domain-boundary walk here;
 * it is the same kind of direct relation `recordedBy` already is.
 *
 * "THE BILL IT SETTLED" READS THE FIRST ALLOCATION, NOT A JOINED SUM
 * -----------------------------------------------------------------------
 * `Payment::allocations()`'s own docblock: design section 2 requires the
 * phase 2 UI to always target exactly one bill, so a payment carries
 * exactly one allocation row in practice even though the schema allows
 * more for a future "pay both my courses at once" flow. The `bill` column
 * below reads `$record->allocations->first()?->charge?->reference` off the
 * eager-loaded relation rather than composing a second SQL aggregate for a
 * figure that is not money — there is nothing here for a float boundary to
 * cross.
 *
 * MONEY NEVER RENDERS AS A BARE DECIMAL STRING
 * -----------------------------------------------
 * Design section 12: composite strings, including money formatting, get
 * their own translation key rather than being assembled by joining
 * fragments in code, because the separator and the ordering are both
 * localisable. `formatMoney()` is the one place that assembly happens,
 * exactly as `ChargeResource::formatMoney()` is for charges.
 *
 * The class name is written out in full in the `@extends` tag
 * deliberately — Pint's phpdoc_types fixer lowercases a bare `Resource`
 * into PHP's `resource` pseudo-type, which silently turns the tag into a
 * reference to nothing. See `ChargeResource`'s identical note.
 *
 * @extends \Filament\Resources\Resource<Payment>
 */
class PaymentResource extends Resource
{
    protected static ?string $model = Payment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $recordTitleAttribute = 'reference';

    public static function getModelLabel(): string
    {
        return __('payments.payment');
    }

    public static function getPluralModelLabel(): string
    {
        return __('payments.payments');
    }

    public static function getNavigationLabel(): string
    {
        return __('payments.payments');
    }

    /**
     * See the class docblock: `PaymentPolicy::create()` is a real,
     * permission-based check, not an unconditional refusal, but this
     * resource registers no create route for `create_payment` to gate
     * anyway. Stated explicitly rather than left to fall out of an absent
     * route, the same defensive style `ChargeResource::canCreate()` and
     * `ActivityResource::canCreate()` use.
     */
    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * Select the tender total alongside every payment column, and
     * eager-load the tenders, student, the recording staff member, and each
     * allocation's bill — without the last one, "the bill it settled" is a
     * textbook N+1, one query per row.
     *
     * `withExists('receiptSnapshot')` selects a boolean in the same statement,
     * which is all `printReceiptAction()` needs to know per row (P4-T24); an
     * eager-loaded relation would cost a second query for a yes or no.
     *
     * NOT `enrollment.student` — there is no such walk here to warn about.
     * See the class docblock for why `student` is a direct, plain relation
     * on this table rather than the domain-boundary violation
     * `ChargeResource` avoids.
     *
     * @return Builder<Payment>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withSum('tenders', 'amount')
            ->withExists('receiptSnapshot')
            ->with(['tenders', 'student', 'recordedBy', 'allocations.charge']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->extraAttributes(['class' => '[&_.fi-ta-record-content-ctn]:py-0! [&_.fi-ta-record-content]:px-0!'])
            // Keep the student and bill links out of a nested row anchor. The
            // authorized reference link retains access to the full receipt.
            ->recordUrl(null)
            ->columns([
                Split::make([
                    Stack::make([
                        TextColumn::make('reference')
                            ->label(__('payments.reference'))
                            ->searchable()
                            ->sortable()->size('xs')->color('gray')
                            ->url(fn (Payment $record): ?string => auth()->user()?->can('view_payment')
                                ? self::getUrl('view', ['record' => $record]) : null),

                        TextColumn::make('student.full_name')
                            ->label(__('payments.student'))->weight('semibold')
                            ->url(fn (Payment $record): ?string => auth()->user()?->can('view_student') && auth()->user()->can('view_any_student') && ! $record->student->trashed()
                                ? StudentResource::getUrl('index', ['student' => $record->student_id]) : null),

                        TextColumn::make('received_at')
                            ->label(__('payments.received_at'))
                            ->dateTime()
                            ->sortable()->size('xs')->color('gray'),
                        TextColumn::make('bill')
                            ->label(__('payments.bill'))
                            ->state(fn (Payment $record): ?string => $record->allocations->first()?->charge?->reference)
                            ->size('xs')->color('gray')
                            ->url(fn (Payment $record): ?string => auth()->user()?->can('view_charge') && $record->allocations->first()?->charge !== null
                                ? ChargeResource::getUrl('view', ['record' => $record->allocations->first()->charge]) : null),
                    ])->space(1),
                    TextColumn::make('tender_chips')->label(__('payments.tenders'))
                        ->state(fn (Payment $record): array => $record->tenders->map(fn (PaymentTender $tender): string => __('payments.tender_chip', [
                            'method' => $tender->method->label(), 'amount' => $tender->amount,
                        ]))->all())->badge()->color('gray')->wrap()
                        ->extraAttributes(['class' => '[&_.fi-badge]:rounded-full! [&_.fi-badge]:px-2.25! [&_.fi-badge]:py-1! [&_.fi-badge]:text-[11.5px]!']),

                    // The SQL shape, not the PHP one — see the class docblock.
                    // `tenders_sum_amount` is Laravel's own generated alias for
                    // `withSum('tenders', 'amount')`; a typo here would
                    // silently render an empty column rather than fail, the
                    // same reasoning `ChargeBalance::OUTSTANDING_ALIAS`
                    // documents. It is a real selected column, not a raw
                    // expression composed into a filter's HAVING, so ORDER BY
                    // resolves it with none of the caveats
                    // `ChargeResource::table()`'s own comment records for its
                    // outstanding filter.
                    TextColumn::make('tenders_sum_amount')
                        ->label(__('payments.total'))
                        ->formatStateUsing(fn (string $state): string => self::formatMoney($state))
                        ->sortable()->weight('semibold')->extraAttributes(['class' => 'text-[15px]! [&_.fi-ta-text-item]:text-[15px]! tabular-nums']),

                    // See the class docblock: exactly one allocation in
                    // practice, so the first is the whole answer.
                    Stack::make([
                        TextColumn::make('payment_state')->label(__('payments.payment_state'))
                            ->state(fn (Payment $record): string => $record->isReversed() ? __('payments.state_reversed') : __('payments.state_recorded'))
                            ->badge()->color(fn (Payment $record): string => $record->isReversed() ? 'danger' : 'success'),
                        TextColumn::make('recordedBy.name')
                            ->label(__('payments.recorded_by'))->size('xs')->color('gray'),

                        /*
                 * Derived from the fact column, never stored —
                 * Payment::isReversed() is the same predicate the model
                 * exposes, read here rather than redefined. NOT a status
                 * column: design section 4 forbids one throughout Finance,
                 * and reversing a payment does not change what it says
                 * happened — it only takes the payment out of every
                 * balance (ChargeBalance's `reversed_at IS NULL` filter).
                 * The tooltip carries the reason so the payment's history
                 * stays visible from the list, matching
                 * ChargeResource::table()'s written_off column.
                 */
                        IconColumn::make('reversed')
                            ->label(__('payments.reversed'))
                            ->state(fn (Payment $record): bool => $record->isReversed())
                            ->boolean()
                            ->falseIcon(false)
                            ->trueColor('danger')
                            ->tooltip(fn (Payment $record): ?string => $record->isReversed()
                                ? $record->reversal_reason
                                : null),
                        TextColumn::make('receipt_pending')->label(__('payments.download_receipt'))
                            ->state(__('payments.receipt_generating'))->size('xs')->color('gray')
                            ->visible(fn (): bool => auth()->user()?->can('view_payment') ?? false)
                            ->formatStateUsing(fn (string $state, Payment $record): ?string => $record->receipt_path === null ? $state : null),
                    ])->space(1),
                ])->from('lg')->extraAttributes(['class' => 'gap-2.75 px-4.5 py-3.5']),
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

                        return $student !== null && Gate::allows('view', $student)
                            ? $query->where('payments.student_id', $id) : $query->whereRaw('1 = 0');
                    }),
                // TernaryFilter::nullable() wires whereNotNull()/whereNull()
                // on the filter's own name, which is a real column here —
                // no HAVING involved, unlike ChargeResource's outstanding
                // filter, so there is no whereRaw caveat to repeat.
                TernaryFilter::make('reversed_at')
                    ->label(__('payments.reversed'))
                    ->trueLabel(__('payments.filter_reversed_true'))
                    ->falseLabel(__('payments.filter_reversed_false'))
                    ->nullable(),
            ])
            ->defaultSort('received_at', 'desc')
            // authorize() on each action, not visible() alone — see the
            // class docblock. Only reverseAction() is shared with
            // ViewPayment's header, so only "reverse" is guaranteed not to
            // drift between the two; the download link is table-only.
            ->recordActions([
                self::downloadReceiptAction(),
                self::printReceiptAction(),
                self::reverseAction(),
            ])
            // No bulk actions of any kind. PaymentPolicy's *Any methods
            // refuse unconditionally (design section 10; see
            // docs/ENGINEERING.md's "Bulk actions cannot be authorized per
            // record" and ChargePolicy's identical reasoning) — stated
            // explicitly rather than left absent, the same defensive style
            // ChargeResource::table() uses.
            ->toolbarActions([]);
    }

    /**
     * A link to the policy-authorized receipt download route (P35-T06).
     *
     * WHY IT EXISTS
     * -------------
     * A finished feature was unreachable. `GenerateReceiptJob` renders the
     * PDF, `receipt_disk` / `receipt_path` record it,
     * `ReceiptDownloadController` serves it and `finance.receipts.download`
     * routes to it — and nothing in the panel linked to that route.
     *
     * AUTHORIZED ON THE SAME ABILITY THE ROUTE RE-CHECKS
     * --------------------------------------------------
     * `authorize('view')` is `PaymentPolicy::view()`, exactly what
     * `ReceiptDownloadController` checks per request after `is_active`.
     * `view_any_payment` opens this list without granting `view_payment`, so
     * without this line that actor would see a button that answers 403.
     * The gate is UX and the route is the boundary — the same split, and the
     * same shape, as `CertificatesRelationManager::downloadAction()`.
     *
     * A LINK HAS NO SERVER HANDLER, SO THIS ACTION GUARDS NOTHING
     * ------------------------------------------------------------
     * Unlike `reverseAction()`, there is no Livewire callback here for
     * `authorize()` to make unmountable. What protects the bytes lives on the
     * route: the `AuthenticatePrivateFileSession` group and `throttle:60,1`,
     * then the controller's guest and `is_active` refusals, its policy check,
     * and its wrong-disk, non-canonical-path and missing-file 404s. Do not
     * move any of that into this action, and do not point the URL anywhere
     * else.
     *
     * HIDDEN UNTIL A RECEIPT PATH IS RECORDED
     * ---------------------------------------
     * `authorize('view')` above decides WHO sees the button; this decides
     * WHEN. The job is queued, so a payment spends time with a null
     * `receipt_path`, and that null is the entire record-state rule: a
     * RECORDED PATH, not a file known to exist. A path pointing at a missing
     * file, the wrong disk or a non-canonical location stays visible and is
     * refused by the controller with 404; repeating those checks here would
     * cost a filesystem call per row and be a second definition of which
     * receipts are valid. `ReceiptDownloadActionTest` pins both sides: a null
     * path hides the button, a recorded path to a missing file keeps it.
     *
     * NOT HIDDEN ON REVERSAL — THE OPPOSITE OF `reverseAction()`
     * ---------------------------------------------------------
     * The receipt was issued. Reversing a payment removes it from every
     * balance without un-issuing the paper, and `ReceiptDownloadTest` already
     * proves the route keeps serving it. This action preserves that decision
     * rather than reopening it.
     */
    public static function downloadReceiptAction(): Action
    {
        return Action::make('downloadReceipt')
            ->label(__('payments.download_receipt'))
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->authorize('view')
            ->visible(fn (Payment $record): bool => $record->receipt_path !== null)
            ->url(fn (Payment $record): string => route('finance.receipts.download', $record))
            ->openUrlInNewTab();
    }

    /**
     * Open the receipt laid out for an 80 mm thermal printer (P4-T24).
     *
     * A LINK, LIKE THE DOWNLOAD. `authorize('view')` decides who sees the
     * button; what protects the page is `finance.receipts.print` — its session
     * and throttle middleware, then the controller's active-user check and
     * `PaymentPolicy::view()`. Do not move any of that here.
     *
     * HIDDEN UNTIL A SNAPSHOT EXISTS, NOT UNTIL A PDF DOES. The page is
     * rendered on demand from the immutable receipt snapshot written when the
     * payment is recorded, so it can print while the queued PDF is still
     * pending. That is the opposite of `downloadReceiptAction()`, which waits
     * for a recorded path. The flag is `getEloquentQuery()`'s `withExists`
     * column: reading the relation here would be one query per row.
     *
     * NOT HIDDEN ON REVERSAL, for the reason the download is not: the receipt
     * was issued and reversing the payment does not un-issue the paper.
     */
    public static function printReceiptAction(): Action
    {
        return Action::make('printReceipt')
            ->label(__('payments.print_receipt'))
            ->icon(Heroicon::OutlinedPrinter)
            ->authorize('view')
            ->visible(fn (Payment $record): bool => (bool) $record->getAttribute('receipt_snapshot_exists'))
            ->url(fn (Payment $record): string => route('finance.receipts.print', $record))
            ->openUrlInNewTab();
    }

    /**
     * Undo a payment (design section 5). Gated on `reverse_payment` via
     * `PaymentPolicy::reverse()`, seeded to super_admin alone.
     *
     * HIDDEN ONCE ALREADY REVERSED — A UX COURTESY, NOT THE GUARD
     * -----------------------------------------------------------------
     * `ReversePaymentAction` refuses a second reversal with
     * `PaymentAlreadyReversedException` regardless of what the panel
     * shows; `->visible()` here only stops an actor from opening a form
     * that would always be refused. Removing this line would not open a
     * security hole — `->authorize()` is what does that job — it would
     * only mean occasionally clicking a button that was always going to
     * fail. Exactly the same shape and the same reasoning as
     * `ChargeResource::writeOffAction()`.
     */
    public static function reverseAction(): Action
    {
        return Action::make('reverse')
            ->label(__('payments.reverse'))
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('danger')
            ->authorize('reverse')
            ->visible(fn (Payment $record): bool => ! $record->isReversed())
            ->modalHeading(__('payments.reverse_modal_heading'))
            ->schema([
                Textarea::make('reason')
                    ->label(__('payments.reason'))
                    ->helperText(__('payments.reverse_reason_hint'))
                    ->required()
                    ->maxLength(1000),
            ])
            ->successNotificationTitle(__('payments.reversed_successfully'))
            ->action(function (Payment $record, array $data, Action $action): void {
                /** @var User $actor */
                $actor = auth()->user();

                try {
                    app(ReversePaymentAction::class)->execute(
                        $actor,
                        (int) $record->getKey(),
                        (string) $data['reason'],
                    );
                } catch (PaymentAlreadyReversedException $exception) {
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
     * Design section 12: money formatting is a composite string and gets
     * its own translation key rather than being joined in code, because
     * the separator and the ordering are both localisable. Exactly
     * `ChargeResource::formatMoney()`'s reasoning, against this task's own
     * catalogue.
     *
     * Takes the raw decimal string a `decimal:3` cast, a SQL `SUM()`, or
     * `Money::toDecimal()` produces, never a `Money` itself — `Money`
     * deliberately has no string conversion (see its own docblock) so
     * that nothing casts it by accident on the way to a view.
     */
    public static function formatMoney(string $decimal): string
    {
        return __('payments.amount_lyd', ['amount' => $decimal]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPayments::route('/'),
            'view' => ViewPayment::route('/{record}'),
        ];
    }
}
