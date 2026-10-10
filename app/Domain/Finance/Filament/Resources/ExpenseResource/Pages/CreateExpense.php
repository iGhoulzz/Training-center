<?php

declare(strict_types=1);

namespace App\Domain\Finance\Filament\Resources\ExpenseResource\Pages;

use App\Domain\Finance\Actions\RecordExpenseAction;
use App\Domain\Finance\Filament\Resources\ExpenseResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Records through the self-authorizing Action, never generic persistence.
 *
 * Filament's inherited handleRecordCreation() is a bare `new $model($data)`: it
 * would let the form choose the recorder and the reversal columns. The Action takes
 * the recorder from the authenticated actor and writes no reversal column at all.
 *
 * Access is gated by CreateRecord::authorizeAccess(), which aborts 403 unless the
 * actor passes ExpensePolicy::create().
 */
final class CreateExpense extends CreateRecord
{
    protected static string $resource = ExpenseResource::class;

    /**
     * MUST be ?bool. Filament declares it ?bool in
     * Filament\Pages\Concerns\CanUseDatabaseTransactions; narrowing to bool is a
     * fatal type error. True so a refusal's Halt can roll the whole save back.
     */
    protected ?bool $hasDatabaseTransactions = true;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return ExpenseResource::attempt(fn (): Model => app(RecordExpenseAction::class)->execute(
            ExpenseResource::actor(),
            (int) ($data['expense_category_id'] ?? 0),
            (string) ($data['amount'] ?? ''),
            (string) ($data['paid_on'] ?? ''),
            (string) ($data['description'] ?? ''),
        ));
    }
}
