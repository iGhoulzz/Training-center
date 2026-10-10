<?php

declare(strict_types=1);

namespace App\Domain\Finance\Actions;

use App\Domain\Finance\Models\Expense;
use App\Domain\Finance\Models\ExpenseCategory;
use App\Domain\Finance\Support\Money;
use App\Models\User;
use App\Support\CentreCalendar;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Record money the centre paid out other than wages: the ONLY way an expense row
 * comes to exist, and the only write an expense ever has apart from its reversal.
 *
 * Actor first and self-authorizing, like every other request-path Action. The
 * Filament create page is one caller; any other must be held to the same rules.
 *
 * AN EXPENSE IS NEVER EDITED
 * --------------------------
 * There is no `UpdateExpenseAction`, and that absence is the enforcement: a wrong
 * expense is reversed (`ReverseExpenseAction`) and the correct figure recorded
 * with this Action as a new row. The recorder is the actor passed in, never a
 * caller-supplied id.
 *
 * AMOUNT IS A STRING FROM FIRST TO LAST
 * -------------------------------------
 * The amount arrives as a decimal string, is validated by pattern on its digits
 * and normalised to three places through `Money`, so it never crosses a float. A
 * fourth decimal place is refused rather than rounded away, and so is zero: money
 * paid out is a positive amount, mirrored by `expenses_amount_positive`.
 *
 * paid_on IS A CENTRE-LOCAL CALENDAR DATE, AND MAY NOT BE AFTER LOCAL TODAY
 * -------------------------------------------------------------------------
 * It is the date the money left. "Today" is the centre's own calendar, not UTC's:
 * for the two hours either side of local midnight the two disagree, and a UTC
 * "today" would refuse the date the till actually shows. A past date is allowed,
 * because an expense is often entered after the fact. The rule is `paidOnRules()`
 * below, one method, shared with the form so the two cannot drift apart, and the
 * single place to change if the owner ever wants a different bound.
 *
 * THE CATEGORY IS LOCKED AND RE-READ BEFORE THE ROW IS WRITTEN
 * ------------------------------------------------------------
 * "Is this category still active?" is decided on a locking read inside the
 * transaction, never on a picker the user opened minutes ago. A category
 * deactivated in the meantime refuses the expense; `UpdateExpenseCategoryAction`
 * takes the same lock, so the two serialise.
 *
 * THE AUDIT TRAIL IS THE MODEL EVENT. The `created` entry is written by
 * RecordsActivity; this Action only names the actor it is attributed to.
 */
final class RecordExpenseAction
{
    public const DESCRIPTION_MAX_LENGTH = 500;

    /**
     * Digits only: up to nine whole digits and at most three decimals, which is
     * exactly what `decimal(12,3)` holds. No sign, no exponent, no separators. The
     * `D` modifier makes `$` mean end of string rather than "or just before a
     * final newline".
     */
    private const AMOUNT_PATTERN = '/^\d{1,9}(?:\.\d{1,3})?$/D';

    /** Zero in any spelling the pattern above admits: "0", "00", "0.000". */
    private const ZERO_PATTERN = '/^0+(?:\.0+)?$/D';

    public function __construct(private readonly CauserResolver $causers) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function execute(
        User $actor,
        int $expenseCategoryId,
        string $amount,
        string $paidOn,
        string $description,
    ): Expense {
        Gate::forUser($actor)->authorize('create', Expense::class);

        $validated = Validator::make([
            'amount' => trim($amount),
            'paid_on' => trim($paidOn),
            'description' => trim($description),
        ], self::rules(), self::validationMessages(), self::attributeNames())->validate();

        return DB::transaction(function () use ($actor, $expenseCategoryId, $validated): Expense {
            $category = ExpenseCategory::query()
                ->lockForUpdate()
                ->find($expenseCategoryId);

            if (! $category instanceof ExpenseCategory || ! $category->is_active) {
                throw ValidationException::withMessages([
                    'expense_category_id' => __('expenses.category_unavailable'),
                ]);
            }

            return $this->causers->withCauser(
                $actor,
                fn (): Expense => Expense::query()->create([
                    'expense_category_id' => $category->getKey(),
                    // Normalised to three places through Money, so "125.5" is
                    // stored and read back as "125.500" with no float in between.
                    'amount' => Money::fromDecimal((string) $validated['amount'])->toDecimal(),
                    'paid_on' => (string) $validated['paid_on'],
                    'description' => (string) $validated['description'],
                    'recorded_by' => (int) $actor->getKey(),
                ]),
            );
        });
    }

    /**
     * The rules for every input this Action reads, keyed by field.
     *
     * Public and static so the Filament form applies the very same rules to its
     * fields: a rule written twice is a rule that drifts.
     *
     * @return array{amount: list<mixed>, paid_on: list<mixed>, description: list<mixed>}
     */
    public static function rules(): array
    {
        return [
            'amount' => self::amountRules(),
            'paid_on' => self::paidOnRules(),
            'description' => ['required', 'string', 'max:'.self::DESCRIPTION_MAX_LENGTH],
        ];
    }

    /**
     * A positive amount of at most three decimals, validated on its digits.
     *
     * Two pattern rules rather than `numeric` + `decimal` + `gt`: those compare
     * through PHP's numeric juggling, and the digits are what the column holds.
     * Declarative rather than a closure so the Filament form can apply the very
     * same list (a closure inside a Filament `rules()` array is evaluated by
     * Filament, not by the validator). `bail` stops at the first refusal so one
     * bad amount produces one message.
     *
     * @return list<string>
     */
    public static function amountRules(): array
    {
        return [
            'bail',
            'required',
            'string',
            'regex:'.self::AMOUNT_PATTERN,
            'not_regex:'.self::ZERO_PATTERN,
        ];
    }

    /**
     * The sentences for the amount's two pattern rules, keyed by rule name.
     *
     * The form passes these to `validationMessages()` as they are; the Action
     * prefixes each with the field name. Either way the two refuse in the same
     * words.
     *
     * @return array{regex: string, not_regex: string}
     */
    public static function amountMessages(): array
    {
        return [
            'regex' => __('expenses.amount_invalid'),
            'not_regex' => __('expenses.amount_must_be_positive'),
        ];
    }

    /**
     * THE ONE RULE FOR HOW LATE paid_on MAY BE: not after the centre's today.
     *
     * Change `before_or_equal` here and nowhere else to move the bound. The
     * centre's calendar, not UTC's, decides what "today" is.
     *
     * @return list<string>
     */
    public static function paidOnRules(): array
    {
        return [
            'required',
            'date_format:Y-m-d',
            'before_or_equal:'.CentreCalendar::localise(now())->toDateString(),
        ];
    }

    /**
     * The Action's validator messages: the amount's two sentences, keyed the way
     * a validator keys a per-field, per-rule message.
     *
     * @return array<string, string>
     */
    private static function validationMessages(): array
    {
        $messages = [];

        foreach (self::amountMessages() as $rule => $message) {
            $messages["amount.{$rule}"] = $message;
        }

        return $messages;
    }

    /**
     * Field names as a person reads them, so a refusal names "Date paid" and not
     * "paid_on".
     *
     * @return array<string, string>
     */
    public static function attributeNames(): array
    {
        return [
            'amount' => __('expenses.amount'),
            'paid_on' => __('expenses.paid_on'),
            'description' => __('expenses.description'),
        ];
    }
}
