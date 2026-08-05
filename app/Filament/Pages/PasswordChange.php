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
use Filament\Support\Enums\Width;
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

    /*
     * THE PAGE RENDERS NO PANEL CHROME (G1-U3).
     *
     * Under the default panel layout this page co-rendered Topbar, Sidebar and
     * GlobalSearch alongside the form, and every one of them was drivable while
     * the account was supposed to be held here. Livewire stamps the ORIGINATING
     * path into each snapshot and hands that path back to the persistent
     * middleware, so a component rendered on this page inherits this page's
     * exemption no matter what it is. The cheapest fix is to render nothing that
     * could inherit it.
     *
     * IT STAYS `extends Page`. The obvious move — SimplePage — deletes the page:
     * the panel calls discoverPages(), which filters on Page::class, and
     * SimplePage extends BasePage instead. The class would stop being discovered
     * and filament.admin.pages.password-change, the route ForcePasswordChange
     * exempts by name, would cease to exist. So the layout is taken directly
     * rather than inherited.
     */
    protected static string $layout = 'filament-panels::components.layout.simple';

    /** @var array<string, mixed> */
    public array $data = [];

    /**
     * @return array<string, mixed>
     */
    protected function getLayoutData(): array
    {
        return [
            /*
             * hasTopbar => false IS REQUIRED, NOT COSMETIC.
             *
             * The simple layout renders SimpleUserMenu and the database
             * notifications component inside one
             * `@if (($hasTopbar ?? true) && filament()->auth()->check())` block.
             * Left at its default this layout would still co-render two
             * components and the leak would survive in reduced form.
             *
             * What cannot be removed here is Filament\Livewire\Notifications:
             * filament-panels::components.layout.base renders it unconditionally
             * for every layout in the panel. It is the flash-message tray, it
             * holds nothing but what it pulled from the session, and
             * ForcePasswordChange refuses to drive it — which is why layer 2
             * exists rather than layer 1 being the whole fix.
             */
            'hasTopbar' => false,
            'maxContentWidth' => Width::Large,
            'maxWidth' => Width::Large,
        ];
    }

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

        /*
         * THE SESSION THAT JUST FIXED THE ACCOUNT STAYS SIGNED IN (G1-U3).
         *
         * AuthenticateSession is persistent, and Livewire's
         * Utils::applyMiddleware() terminates its pipeline in an empty Response —
         * so the middleware's after-callback, the one that refreshes
         * password_hash_{guard}, fires at `snapshot-verified` time, BEFORE this
         * method runs. It therefore stored the OLD hash. The password then
         * changes underneath it, the next request compares and mismatches, and
         * the user is logged out of the session they just used to comply. The
         * success redirect landed on the login page.
         *
         * Refreshing the hash here is what Laravel's own self-service password
         * change does, and what Filament's EditProfile does. OTHER sessions still
         * fail on their stale hash, which is the containment an administrator
         * forcing a reset is actually buying. Regenerating the id on a credential
         * change is the ordinary hygiene that goes with it.
         *
         * Filament::getAuthGuard() rather than the panel-agnostic default: it is
         * the key Filament itself writes. If a panel is ever given its own guard
         * and the two diverge, this stops matching and the user is logged out —
         * inconvenient, never permissive.
         */
        if (request()->hasSession()) {
            request()->session()->put([
                'password_hash_'.Filament::getAuthGuard() => $user->getAuthPassword(),
            ]);

            request()->session()->regenerate();
        }

        Notification::make()->title(__('auth.password_updated'))->success()->send();

        $this->redirect('/admin');
    }
}
