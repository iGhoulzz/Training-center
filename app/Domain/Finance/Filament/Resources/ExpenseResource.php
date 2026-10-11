<?php

declare(strict_types=1);

namespace App\Domain\Finance\Filament\Resources;

use App\Domain\Finance\Actions\RecordExpenseAction;
use App\Domain\Finance\Actions\ReverseExpenseAction;
use App\Domain\Finance\Filament\Resources\ExpenseResource\Pages\CreateExpense;
use App\Domain\Finance\Filament\Resources\ExpenseResource\Pages\ListExpenses;
use App\Domain\Finance\Filament\Resources\ExpenseResource\Pages\ViewExpense;
use App\Domain\Finance\Models\Expense;
use App\Domain\Finance\Models\ExpenseCategory;
use App\Models\User;
use App\Support\CentreCalendar;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * Money paid out other than wages: listed, recorded, viewed, and — by a super
 * admin — reversed. Never edited, never deleted.
 *
 * WHY THERE IS NO EDIT PAGE AND NO DELETE ACTION
 * ----------------------------------------------
 * An expense's amount, date, category and description have no write path after the
 * row exists. `getPages()` registers `index`, `create` and `view`; no `EditExpense`
 * exists anywhere in this namespace, and `ExpensePolicy::update()` and `delete()`
 * return false for every actor. A wrong expense is REVERSED and the correct figure
 * recorded as a new row, which is the one correction this resource offers.
 * `ExpenseResourceTest` proves through Livewire that no edit or delete control can
 * be reached, so the claim does not rest on this paragraph.
 *
 * EVERY WRITE ROUTES THROUGH AN ACTION
 * ------------------------------------
 * The create page replaces Filament's generic persistence with
 * `RecordExpenseAction`, and the reverse action calls `ReverseExpenseAction`.
 * There is no bare `Expense::create()` or `$record->update()` here. A full create
 * page rather than a modal action, because Filament's modal CreateAction persists
 * with a bare create() that never reaches a page hook (docs/ENGINEERING.md).
 *
 * THE AMOUNT FIELD IS NEVER ->numeric()
 * -------------------------------------
 * `->numeric()` installs a float state cast on the input, so the value would cross
 * a float before any Action saw it. The field keeps its string state, asks the
 * device for a decimal keyboard with `inputMode('decimal')`, and takes its rules
 * from `RecordExpenseAction::amountRules()`, which validates the digits. The same
 * rules run again in the Action. `MoneyCastArchTest` fails the build on a
 * `->numeric()` call on a financial field.
 *
 * THE FORM SHARES THE ACTION'S RULES
 * ----------------------------------
 * `RecordExpenseAction::rules()` is applied to the fields, so the form and the
 * Action cannot drift apart on what a valid amount or date is. In particular the
 * rule that `paid_on` may not be after the centre-local today lives in exactly one
 * place.
 *
 * ACTIONS ARE AUTHORIZED, NOT MERELY HIDDEN
 * -----------------------------------------
 * `->authorize('reverse')` runs `ExpensePolicy::reverse()` on the server and makes
 * the action unmountable for anyone without `reverse_expense`. `->visible()` only
 * hides the button once the row is reversed — a UX courtesy, not the guard:
 * `ReverseExpenseAction` refuses a second reversal on a fresh locking read whatever
 * the panel shows.
 *
 * MONEY NEVER RENDERS AS A BARE DECIMAL STRING
 * --------------------------------------------
 * Money formatting is a composite string and gets its own translation key rather
 * than being assembled by joining fragments in code, because the separator and the
 * ordering are both localisable. `formatMoney()` is the one place that assembly
 * happens for this resource.
 *
 * The class name is written out in full in the `@extends` tag deliberately —
 * Pint's phpdoc_types fixer lowercases a bare `Resource` into PHP's `resource`
 * pseudo-type, which silently turns the tag into a reference to nothing.
 *
 * @extends \Filament\Resources\Resource<Expense>
 */
final class ExpenseResource extends Resource
{
    protected static ?string $model = Expense::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWallet;

    protected static ?string $recordTitleAttribute = 'description';

    public static function getModelLabel(): string
    {
        return __('expenses.expense');
    }

    public static function getPluralModelLabel(): string
    {
        return __('expenses.expenses');
    }

    public static function getNavigationLabel(): string
    {
        return __('expenses.expenses');
    }

    /**
     * Eager-load what the list renders, without which each row costs two more
     * queries.
     *
     * @return Builder<Expense>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['category', 'recordedBy']);
    }

    public static function form(Schema $schema): Schema
    {
        $rules = RecordExpenseAction::rules();

        return $schema->components([
            // Only active categories are offered. RecordExpenseAction re-checks on
            // a locking read, so a stale picker cannot record against one that has
            // since been switched off.
            Select::make('expense_category_id')
                ->label(__('expenses.category'))
                ->options(fn (): array => self::activeCategoryOptions())
                ->required()
                ->searchable(),

            TextInput::make('amount')
                ->label(__('expenses.amount'))
                ->helperText(__('expenses.amount_hint'))
                ->required()
                ->inputMode('decimal')
                ->rules($rules['amount'])
                ->validationMessages(RecordExpenseAction::amountMessages()),

            DatePicker::make('paid_on')
                ->label(__('expenses.paid_on'))
                ->helperText(__('expenses.paid_on_hint'))
                ->default(CentreCalendar::localise(now())->toDateString())
                ->required()
                ->rules($rules['paid_on']),

            Textarea::make('description')
                ->label(__('expenses.description'))
                ->required()
                ->rows(3)
                ->maxLength(RecordExpenseAction::DESCRIPTION_MAX_LENGTH),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('paid_on')
                    ->label(__('expenses.paid_on'))
                    ->date()
                    ->sortable(),

                TextColumn::make('category.name_en')
                    ->label(__('expenses.category'))
                    ->state(fn (Expense $record): string => $record->category->name()),

                TextColumn::make('description')
                    ->label(__('expenses.description'))
                    ->limit(60)
                    ->searchable(),

                TextColumn::make('amount')
                    ->label(__('expenses.amount'))
                    ->formatStateUsing(fn (string $state): string => self::formatMoney($state))
                    ->sortable(),

                TextColumn::make('recordedBy.name')
                    ->label(__('expenses.recorded_by')),

                /*
                 * Derived from the fact column, never stored. Reversing an expense
                 * does not change what it says happened; it only takes it out of
                 * every profit figure. The tooltip carries the reason so the
                 * history stays visible from the list.
                 */
                IconColumn::make('reversed')
                    ->label(__('expenses.reversed'))
                    ->state(fn (Expense $record): bool => $record->isReversed())
                    ->boolean()
                    ->tooltip(fn (Expense $record): ?string => $record->isReversed()
                        ? $record->reversal_reason
                        : null),
            ])
            ->filters([
                SelectFilter::make('expense_category_id')
                    ->label(__('expenses.category'))
                    ->options(fn (): array => self::allCategoryOptions()),

                TernaryFilter::make('reversed_at')
                    ->label(__('expenses.reversed'))
                    ->trueLabel(__('expenses.filter_reversed_true'))
                    ->falseLabel(__('expenses.filter_reversed_false'))
                    ->nullable(),
            ])
            ->defaultSort('paid_on', 'desc')
            ->recordActions([
                Action::make('view')
                    ->label(__('expenses.view_expense'))
                    ->icon(Heroicon::OutlinedEye)
                    ->authorize('view')
                    ->url(fn (Expense $record): string => self::getUrl('view', ['record' => $record])),

                self::reverseAction(),
            ])
            // No bulk actions of any kind, and no edit or delete action above. See
            // the class docblock: ExpensePolicy refuses every *Any ability, because
            // a bulk action is authorized once and never per record.
            ->toolbarActions([]);
    }

    /**
     * Undo an expense. Gated on `reverse_expense` through `ExpensePolicy::reverse()`,
     * seeded to super admin alone.
     *
     * Shared as a static builder with `ViewExpense`, exactly as
     * `PaymentResource::reverseAction()` is, so the table row and the view page
     * cannot drift apart on what "reverse" does.
     */
    public static function reverseAction(): Action
    {
        return Action::make('reverse')
            ->label(__('expenses.reverse'))
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('danger')
            ->authorize('reverse')
            ->visible(fn (Expense $record): bool => ! $record->isReversed())
            ->modalHeading(__('expenses.reverse_modal_heading'))
            ->modalDescription(__('expenses.reverse_modal_description'))
            ->schema([
                Textarea::make('reason')
                    ->label(__('expenses.reason'))
                    ->helperText(__('expenses.reverse_reason_hint'))
                    ->required()
                    ->maxLength(ReverseExpenseAction::REASON_MAX_LENGTH),
            ])
            ->successNotificationTitle(__('expenses.reversed_successfully'))
            ->action(function (Expense $record, array $data, Action $action): void {
                self::attempt(fn (): Expense => app(ReverseExpenseAction::class)->execute(
                    self::actor(),
                    $record,
                    (string) $data['reason'],
                ));

                $action->success();
            });
    }

    /**
     * Run a write and turn the two outcomes the panel can explain into a
     * notification, a Halt and a rollback.
     *
     * Only an authorization denial and a refusal with a message the Action wrote are
     * caught. Anything else propagates; a real fault must not be dressed up as a
     * notification. Shared with the category pages so both resources refuse alike.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $write
     * @return TReturn
     *
     * @throws Halt
     */
    public static function attempt(Closure $write): mixed
    {
        try {
            return $write();
        } catch (AuthorizationException) {
            self::refuse(__('expenses.refused_unauthorized'));
        } catch (ValidationException $exception) {
            self::refuse($exception->validator->errors()->first() ?: __('expenses.refused_invalid'));
        }
    }

    public static function actor(): User
    {
        /** @var User $actor */
        $actor = auth()->user();

        return $actor;
    }

    /**
     * The one place a dirham amount is assembled into user-facing text.
     *
     * Takes the raw decimal string a `decimal:3` cast or a SQL `SUM()` produces,
     * never a `Money` itself — `Money` deliberately has no string conversion, so
     * that nothing casts it by accident on the way to a view.
     */
    public static function formatMoney(string $decimal): string
    {
        return __('expenses.amount_lyd', ['amount' => $decimal]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListExpenses::route('/'),
            'create' => CreateExpense::route('/create'),
            'view' => ViewExpense::route('/{record}'),
        ];
    }

    /**
     * Categories a new expense may be recorded against, by id, in the reader's
     * language.
     *
     * @return array<int, string>
     */
    private static function activeCategoryOptions(): array
    {
        return ExpenseCategory::query()
            ->active()
            ->orderBy('name_en')
            ->get()
            ->mapWithKeys(fn (ExpenseCategory $category): array => [(int) $category->getKey() => $category->name()])
            ->all();
    }

    /**
     * Every category, deactivated ones included: the list filter has to reach
     * expenses recorded against a category that has since been switched off.
     *
     * @return array<int, string>
     */
    private static function allCategoryOptions(): array
    {
        return ExpenseCategory::query()
            ->orderBy('name_en')
            ->get()
            ->mapWithKeys(fn (ExpenseCategory $category): array => [(int) $category->getKey() => $category->name()])
            ->all();
    }

    private static function refuse(string $title): never
    {
        Notification::make()
            ->title($title)
            ->danger()
            ->persistent()
            ->send();

        throw (new Halt)->rollBackDatabaseTransaction();
    }
}
