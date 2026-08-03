<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Staff\Support\ActivityEvent;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use LogicException;

/**
 * `$form` is resolved by Livewire's __get via InteractsWithSchemas. Declaring
 * it matches how Filament's own auth pages type the same magic property.
 *
 * @property-read Schema $form
 */
class PasswordChange extends Page
{
    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.password-change';

    /** @var array<string, mixed> */
    public array $data = [];

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                /*
                 * THE CURRENT PASSWORD IS REQUIRED (P1-T15, security finding 6).
                 *
                 * Without it, holding a session was enough to own the account
                 * permanently: anyone with a stolen or fixated cookie could set
                 * a new password, lock the legitimate holder out, and need never
                 * have known the old one. Session access and credential
                 * ownership are different things, and this is what keeps them
                 * apart.
                 *
                 * ResetUserPasswordAction names the same primitive from the
                 * other side — "resetting a password is an account-takeover
                 * primitive" — and guards it with the reset_user_password
                 * ability. The self-service path had no equivalent gate at all.
                 *
                 * Laravel's current_password rule hashes and compares against
                 * the authenticated user, so the value is never logged or
                 * persisted; it is dehydrated so it cannot reach save().
                 */
                TextInput::make('current_password')
                    ->label(__('auth.current_password'))
                    ->password()
                    ->required()
                    ->currentPassword()
                    ->dehydrated(false),

                TextInput::make('password')
                    ->label(__('auth.new_password'))
                    ->password()
                    ->required()
                    ->minLength(12)
                    ->confirmed(),
                TextInput::make('password_confirmation')
                    ->label(__('auth.confirm_password'))
                    ->password()
                    ->required(),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $state = $this->form->getState();

        $user = Filament::auth()->user();

        if (! $user instanceof User) {
            throw new LogicException('The authenticated user must be an App\Models\User to change a password.');
        }

        /*
         * The change and its audit entry share one transaction.
         *
         * The entry is explicit for the same reason ResetUserPasswordAction's is:
         * `password` is absent from User::auditedAttributes() so no hash reaches
         * the log, and an account that was NOT flagged for rotation moves no
         * audited column at all — the diff would be empty, suppressed, and a
         * self-service password change would leave no trace whatsoever.
         *
         * Distinguished from password_reset because they answer different
         * questions: this is somebody changing their OWN credentials, which needs
         * no second party, while a reset is an administrator acting on another
         * account.
         */
        DB::transaction(function () use ($user, $state): void {
            $user->update([
                'password' => Hash::make($state['password']),
                'must_change_password' => false,
            ]);

            activity()
                // Explicit rather than relying on the ambient guard: the actor is
                // the user this page resolved and authorized, and an entry whose
                // attribution depends on guard state is one that silently loses it
                // the day this runs anywhere but a web request.
                ->causedBy($user)
                ->performedOn($user)
                ->event(ActivityEvent::PASSWORD_CHANGED)
                ->log(ActivityEvent::PASSWORD_CHANGED);
        });

        Notification::make()->title(__('auth.password_updated'))->success()->send();

        $this->redirect('/admin');
    }
}
