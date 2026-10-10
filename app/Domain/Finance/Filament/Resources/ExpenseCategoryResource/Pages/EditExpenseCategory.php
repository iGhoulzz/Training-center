<?php

declare(strict_types=1);

namespace App\Domain\Finance\Filament\Resources\ExpenseCategoryResource\Pages;

use App\Domain\Finance\Actions\UpdateExpenseCategoryAction;
use App\Domain\Finance\Filament\Resources\ExpenseCategoryResource;
use App\Domain\Finance\Filament\Resources\ExpenseResource;
use App\Domain\Finance\Models\ExpenseCategory;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Edits through the self-authorizing Action, never generic persistence.
 *
 * Filament's inherited handleRecordUpdate() is a bare `$record->update($data)`; the
 * Action owns the authorization, the row lock and the audit attribution.
 *
 * NO HEADER ACTIONS, ON PURPOSE. In particular no DeleteAction: a category is
 * deactivated with the `is_active` switch, never deleted, and the Filament
 * generator's default Edit page would have added one.
 *
 * Access is gated by EditRecord::authorizeAccess(): 403 unless the actor passes
 * ExpenseCategoryPolicy::update().
 */
final class EditExpenseCategory extends EditRecord
{
    protected static string $resource = ExpenseCategoryResource::class;

    /**
     * MUST be ?bool, as on CreateExpenseCategory. True so a refusal's Halt rolls
     * the whole save back rather than leaving part of it committed.
     */
    protected ?bool $hasDatabaseTransactions = true;

    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var ExpenseCategory $record */
        return ExpenseResource::attempt(fn (): ExpenseCategory => app(UpdateExpenseCategoryAction::class)->execute(
            ExpenseResource::actor(),
            $record,
            (string) ($data['name_en'] ?? ''),
            isset($data['name_ar']) ? (string) $data['name_ar'] : null,
            (bool) ($data['is_active'] ?? false),
        ));
    }
}
