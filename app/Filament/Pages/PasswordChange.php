<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
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

        $user->update([
            'password' => Hash::make($state['password']),
            'must_change_password' => false,
        ]);

        Notification::make()->title(__('auth.password_updated'))->success()->send();

        $this->redirect('/admin');
    }
}
