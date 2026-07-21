<?php

declare(strict_types=1);

namespace App\Domain\Staff\Filament\Resources\UserResource\Pages;

use App\Domain\Staff\Actions\ResetUserPasswordAction;
use App\Domain\Staff\Filament\Resources\UserResource;
use App\Domain\Staff\Filament\Resources\UserResource\Concerns\WritesUserThroughActions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;

/**
 * Creating a staff account.
 *
 * THE ACCOUNT NEVER GETS A CHOSEN PASSWORD
 * ----------------------------------------
 * `users.password` is non-nullable, so the insert needs a value, but the form has
 * no password field on purpose — an administrator typing a password for someone
 * else is a credential they now know. The row is inserted with a random value
 * nobody has seen, and afterCreate() immediately calls ResetUserPasswordAction,
 * the one flow that issues a temporary password: it hashes a fresh 16-character
 * secret, sets must_change_password, and returns the plaintext to display once.
 *
 * Reusing that Action rather than duplicating its logic also means the actor is
 * authorized to issue this account's credential (UserPolicy::resetPassword),
 * not merely to create a row. The whole save is transactional, so a refusal
 * takes the half-created account with it and never leaves an account whose
 * password nobody knows.
 */
class CreateUser extends CreateRecord
{
    use WritesUserThroughActions;

    protected static string $resource = UserResource::class;

    /**
     * Wrap the whole create so an afterCreate() refusal removes the record.
     *
     * The type MUST be ?bool — Filament declares the property as ?bool in
     * Filament\Pages\Concerns\CanUseDatabaseTransactions, and narrowing it to
     * bool is a fatal incompatible-property-type error.
     */
    protected ?bool $hasDatabaseTransactions = true;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // A throwaway that satisfies the non-nullable column and is overwritten
        // by ResetUserPasswordAction moments later, inside the same transaction.
        // It is never shown to anyone.
        $data['password'] = Str::password(32);

        return $data;
    }

    protected function afterCreate(): void
    {
        $record = $this->userRecord();

        // The insert omitted is_active (the toggle is dehydrated(false)), so the
        // in-memory model does not carry the column's database default yet.
        // Without this refresh the active-state comparison would read null and
        // deactivate every new account.
        $record->refresh();

        $this->writeGuardedUserState();

        try {
            $plain = app(ResetUserPasswordAction::class)->execute($this->currentActor(), $record);
        } catch (AuthorizationException) {
            $this->refuseSave(__('staff.create_refused_no_password_permission'));
        }

        // persistent() is deliberate: the plaintext appears exactly once and the
        // administrator must dismiss it by hand.
        Notification::make()
            ->title(__('staff.temp_password_generated'))
            ->body($plain)
            ->persistent()
            ->warning()
            ->send();
    }
}
