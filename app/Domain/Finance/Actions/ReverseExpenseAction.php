<?php

declare(strict_types=1);

namespace App\Domain\Finance\Actions;

use App\Domain\Finance\Models\Expense;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Void an expense recorded in error: a set-once lifecycle transition on an
 * otherwise immutable row, and how a wrong expense is corrected (spec section 8).
 *
 * THIS IS HOW A MISTAKE IS FIXED, BECAUSE AN EXPENSE IS NEVER EDITED
 * ------------------------------------------------------------------
 * The wrong amount, date or category cannot be changed, so the row is reversed and
 * the correct figure recorded as a new expense. A reversed expense drops out of
 * `ExpenseReport` and therefore out of Profit, which moves the profit of the month
 * it was paid in. That is deliberate and traceable: it moves only through this
 * Action, attributed to a super admin, with a reason, in the append-only activity
 * log. Never claim a closed month does not move.
 *
 * NOTHING IS ERASED, AND NOTHING IS DELETED
 * -----------------------------------------
 * The expense's financial facts — category, amount, date, description, recorder —
 * are never rewritten. Only the three reversal columns are written, together, once.
 *
 * AUTHORIZATION RUNS BEFORE ANYTHING IS LOADED
 * --------------------------------------------
 * `reverse_expense` is super admin only and does not depend on any one expense's
 * state, so authorizing first costs nothing and means an actor without the ability
 * never learns whether the expense they named exists. The already-reversed refusal
 * is deliberately NOT folded into `ExpensePolicy::reverse()`: whether an actor may
 * reverse expenses at all is a question about the ACTOR, and whether this expense
 * already carries a reversal is a question about the ROW.
 *
 * "ALREADY REVERSED?" IS DECIDED ON A FRESH LOCKING READ, NEVER ON THE ARGUMENT
 * ------------------------------------------------------------------------------
 * The `$expense` passed in is only a way to name a row. It may have been loaded
 * before another session reversed it — a second browser tab, a list that has sat
 * open — so its `reversed_at` says nothing about the database. The row is re-read
 * with `lockForUpdate()` inside the transaction, and THAT instance decides. Two
 * concurrent attempts then cannot both see "not yet reversed": the second waits for
 * the first to commit, sees the reversal, and is refused. A second reversal is
 * refused outright rather than re-stamped, because re-stamping would destroy the
 * fact these columns exist to record: who decided this expense was wrong, and when.
 *
 * ALL THREE COLUMNS, IN ONE update(), OR THE DATABASE REFUSES THE ROW
 * -------------------------------------------------------------------
 * `expenses_reversal_columns_paired` requires all three reversal columns or none,
 * so a reversal with no actor or no reason is not a state the table can hold.
 *
 * EVERY WRITE RUNS UNDER THE ACTOR, NOT THE SESSION
 * -------------------------------------------------
 * Spatie resolves the activity-log causer from the authenticated session, while
 * `reversed_by` is written from the actor this Action was PASSED. The update runs
 * inside `CauserResolver::withCauser($actor, ...)` so a console or queued
 * invocation, or one made while somebody else holds the session, logs the actor.
 * The reason travels into the log with the `updated` entry: `reversal_reason` is an
 * audited column.
 */
final class ReverseExpenseAction
{
    public const REASON_MAX_LENGTH = 1000;

    public function __construct(private readonly CauserResolver $causers) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException if the reason is blank, or the expense already carries a reversal.
     */
    public function execute(User $actor, Expense $expense, string $reason): Expense
    {
        Gate::forUser($actor)->authorize('reverse', Expense::class);

        $validated = Validator::make(
            ['reason' => trim($reason)],
            ['reason' => ['required', 'string', 'max:'.self::REASON_MAX_LENGTH]],
            [],
            ['reason' => __('expenses.reason')],
        )->validate();

        return DB::transaction(function () use ($actor, $expense, $validated): Expense {
            $lockedExpense = Expense::query()
                ->lockForUpdate()
                ->findOrFail($expense->getKey());

            if ($lockedExpense->isReversed()) {
                throw ValidationException::withMessages([
                    'expense' => __('expenses.already_reversed'),
                ]);
            }

            $this->causers->withCauser(
                $actor,
                fn (): bool => $lockedExpense->update([
                    'reversed_at' => now(),
                    'reversed_by' => (int) $actor->getKey(),
                    'reversal_reason' => (string) $validated['reason'],
                ]),
            );

            return $lockedExpense;
        });
    }
}
