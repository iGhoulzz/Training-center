<?php

declare(strict_types=1);

namespace App\Domain\Finance\Filament\Resources\ExpenseCategoryResource\Pages;

use App\Domain\Finance\Actions\CreateExpenseCategoryAction;
use App\Domain\Finance\Filament\Resources\ExpenseCategoryResource;
use App\Domain\Finance\Filament\Resources\ExpenseResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Creates through the self-authorizing Action, never generic persistence.
 *
 * Access is gated by CreateRecord::authorizeAccess(), which aborts 403 unless the
 * actor passes ExpenseCategoryPolicy::create().
 */
final class CreateExpenseCategory extends CreateRecord
{
    protected static string $resource = ExpenseCategoryResource::class;

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
        return ExpenseResource::attempt(fn (): Model => app(CreateExpenseCategoryAction::class)->execute(
            ExpenseResource::actor(),
            (string) ($data['name_en'] ?? ''),
            isset($data['name_ar']) ? (string) $data['name_ar'] : null,
        ));
    }
}
