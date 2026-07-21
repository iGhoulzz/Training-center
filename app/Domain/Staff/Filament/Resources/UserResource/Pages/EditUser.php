<?php

declare(strict_types=1);

namespace App\Domain\Staff\Filament\Resources\UserResource\Pages;

use App\Domain\Staff\Filament\Resources\UserResource;
use App\Domain\Staff\Filament\Resources\UserResource\Concerns\WritesUserThroughActions;
use Filament\Resources\Pages\EditRecord;

/**
 * Editing a staff account.
 *
 * The record's own attributes are written by Filament; the roles and is_active
 * fields are not — they are dehydrated(false) and are applied in afterSave()
 * through SyncUserRolesAction / DeactivateUserAction / ActivateUserAction, which
 * authorize the acting user. See the WritesUserThroughActions concern.
 */
class EditUser extends EditRecord
{
    use WritesUserThroughActions;

    protected static string $resource = UserResource::class;

    /**
     * Wrap the whole save so an afterSave() refusal undoes the record update
     * instead of leaving a partial save (the rename committed, the role change
     * refused).
     *
     * The type MUST be ?bool. Filament declares it as
     * `protected ?bool $hasDatabaseTransactions = null;` in
     * Filament\Pages\Concerns\CanUseDatabaseTransactions — redeclaring it as
     * `bool` is a fatal "type of property must be compatible" error.
     */
    protected ?bool $hasDatabaseTransactions = true;

    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function afterSave(): void
    {
        $this->writeGuardedUserState();
    }
}
