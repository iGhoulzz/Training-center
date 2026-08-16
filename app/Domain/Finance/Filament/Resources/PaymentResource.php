<?php

declare(strict_types=1);

namespace App\Domain\Finance\Filament\Resources;

use App\Domain\Finance\Actions\ReversePaymentAction;
use App\Domain\Finance\Exceptions\PaymentAlreadyReversedException;
use App\Domain\Finance\Filament\Resources\PaymentResource\Pages\ListPayments;
use App\Domain\Finance\Filament\Resources\PaymentResource\Pages\ViewPayment;
use App\Domain\Finance\Models\Payment;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Payments — read-plus-one-action, never created or edited through the panel
 * (P2-T04).
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
 * ONE ACTION, AUTHORIZED, NOT MERELY HIDDEN
 * -------------------------------------------
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
     * eager-load the student, the recording staff member, and each
     * allocation's bill — without the last one, "the bill it settled" is a
     * textbook N+1, one query per row.
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
            ->with(['student', 'recordedBy', 'allocations.charge']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')
                    ->label(__('payments.reference'))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('student.full_name')
                    ->label(__('payments.student')),

                TextColumn::make('received_at')
                    ->label(__('payments.received_at'))
                    ->dateTime()
                    ->sortable(),

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
                    ->sortable(),

                // See the class docblock: exactly one allocation in
                // practice, so the first is the whole answer.
                TextColumn::make('bill')
                    ->label(__('payments.bill'))
                    ->state(fn (Payment $record): ?string => $record->allocations->first()?->charge?->reference),

                TextColumn::make('recordedBy.name')
                    ->label(__('payments.recorded_by')),

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
                    ->tooltip(fn (Payment $record): ?string => $record->isReversed()
                        ? $record->reversal_reason
                        : null),
            ])
            ->filters([
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
            // authorize() on the action, not visible() alone — see the
            // class docblock. A shared builder so the table row and
            // ViewPayment's header action cannot drift apart.
            ->recordActions([
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
            ->icon(Heroicon::OutlinedReceiptRefund)
            ->color('danger')
            ->authorize('reverse')
            ->visible(fn (Payment $record): bool => ! $record->isReversed())
            ->modalHeading(__('payments.reverse_modal_heading'))
            ->schema([
                Textarea::make('reason')
                    ->label(__('payments.reason'))
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
