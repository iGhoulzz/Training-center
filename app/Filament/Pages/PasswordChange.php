<?php

declare(strict_types=1);

namespace App\Filament\Pages;

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
                ->event('password_changed')
                ->log('password_changed');
        });

        Notification::make()->title(__('auth.password_updated'))->success()->send();

        $this->redirect('/admin');
    }
}
