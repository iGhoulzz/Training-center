<?php

declare(strict_types=1);

namespace App\Domain\Staff\Filament\Resources\UserResource\Concerns;

use App\Domain\Staff\Actions\ActivateUserAction;
use App\Domain\Staff\Actions\DeactivateUserAction;
use App\Domain\Staff\Actions\SyncUserRolesAction;
use App\Domain\Staff\Exceptions\LastSuperAdminException;
use App\Models\User;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The save-hook half of UserResource's Action boundary, shared by CreateUser and
 * EditUser so the two pages cannot drift apart on security-relevant code.
 *
 * WHY getRawState() AND NOT $data
 * -------------------------------
 * `roles` and `is_active` are `dehydrated(false)`, which means Filament EXCLUDES
 * them from the `$data` array handed to mutateFormDataBeforeSave() and the record
 * write. Reading `$data['roles']` here would yield `[]` on every save and
 * SyncUserRolesAction would dutifully strip every role from the account.
 * `$this->form->getRawState()` is the live component state and does include them.
 *
 * WHY A REFUSAL THROWS Halt WITH A ROLLBACK
 * -----------------------------------------
 * The record's own attributes (name, email, locale) are already written by the
 * time these hooks run. Simply reporting the refusal would leave a partial save:
 * the rename committed, the role change refused. Both pages set
 * `$hasDatabaseTransactions = true`, and Halt::rollBackDatabaseTransaction()
 * tells Filament's catch block to roll the whole save back, so a rejected role or
 * activation change undoes the attribute write with it.
 *
 * Only the two domain refusals are caught — an authorization denial and the
 * last-super-admin business rule. Anything else propagates; a genuine fault must
 * not be dressed up as a polite notification.
 */
trait WritesUserThroughActions
{
    /**
     * Persist the two non-dehydrated fields through their Actions.
     */
    protected function writeGuardedUserState(): void
    {
        try {
            $this->syncRolesThroughAction();
            $this->applyActiveStateThroughAction();
        } catch (AuthorizationException) {
            $this->refuseSave(__('staff.save_refused_unauthorized'));
        } catch (LastSuperAdminException) {
            $this->refuseSave(__('staff.save_refused_last_super_admin'));
        }
    }

    protected function syncRolesThroughAction(): void
    {
        $roles = $this->rawFormState()['roles'] ?? [];

        app(SyncUserRolesAction::class)->execute(
            $this->currentActor(),
            $this->userRecord(),
            is_array($roles) ? array_values(array_filter($roles, 'is_string')) : [],
        );
    }

    protected function applyActiveStateThroughAction(): void
    {
        $record = $this->userRecord();
        $shouldBeActive = (bool) ($this->rawFormState()['is_active'] ?? true);

        if ($shouldBeActive === (bool) $record->is_active) {
            return;
        }

        $shouldBeActive
            ? app(ActivateUserAction::class)->execute($this->currentActor(), $record)
            : app(DeactivateUserAction::class)->execute($this->currentActor(), $record);
    }

    /**
     * Report the refusal and unwind the whole save.
     */
    protected function refuseSave(string $title): never
    {
        Notification::make()
            ->title($title)
            ->danger()
            ->persistent()
            ->send();

        throw (new Halt)->rollBackDatabaseTransaction();
    }

    /**
     * @return array<string, mixed>
     */
    protected function rawFormState(): array
    {
        $state = $this->form->getRawState();

        return is_array($state) ? $state : $state->toArray();
    }

    protected function currentActor(): User
    {
        /** @var User $actor */
        $actor = auth()->user();

        return $actor;
    }

    protected function userRecord(): User
    {
        /** @var User $record */
        $record = $this->record;

        return $record;
    }
}
