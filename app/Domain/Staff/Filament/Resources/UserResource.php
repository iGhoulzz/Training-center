<?php

declare(strict_types=1);

namespace App\Domain\Staff\Filament\Resources;

use App\Domain\Staff\Actions\DeleteUserAction;
use App\Domain\Staff\Actions\ResetUserPasswordAction;
use App\Domain\Staff\Exceptions\LastSuperAdminException;
use App\Domain\Staff\Filament\Resources\UserResource\Pages\CreateUser;
use App\Domain\Staff\Filament\Resources\UserResource\Pages\EditUser;
use App\Domain\Staff\Filament\Resources\UserResource\Pages\ListUsers;
// App\Models\Role, never the vendor model — the architecture test fails the
// build on a Spatie\Permission\Models\Role import outside app/Models/Role.php.
use App\Models\Role;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Staff account management (P1-T05).
 *
 * THE TWO FIELDS THAT DO NOT PERSIST THEMSELVES
 * ---------------------------------------------
 * `roles` and `is_active` are both `dehydrated(false)`, which excludes them from
 * the data array Filament writes to the model. Neither may be model-bound:
 *
 *   - `Select::make('roles')->relationship('roles')` persists through the
 *     relation's sync()/detach(), reaching around every escalation guard — and
 *     unlike the raw-SQL bypasses, it IS reachable from the UI.
 *   - A plain `Toggle::make('is_active')` writes the column directly, skipping
 *     DeactivateUserAction and its last-super-admin invariant.
 *
 * The pages under UserResource/Pages read both fields from the live form state
 * and hand them to SyncUserRolesAction / DeactivateUserAction /
 * ActivateUserAction. See the WritesUserThroughActions concern.
 *
 * FILTERING THE OPTIONS LIST IS NOT THE CONTROL
 * ---------------------------------------------
 * The role dropdown below offers every role, and `visible()` on the record
 * actions is a UX affordance only. A crafted Livewire payload can submit a role
 * the dropdown never rendered and can invoke a hidden action. Every one of these
 * paths therefore re-authorizes inside its Action on execute; that is the
 * boundary, and UserResourceTest drives the real component to prove it.
 *
 * WHY THERE IS NO EditAction ON THE TABLE
 * ---------------------------------------
 * Filament's EditAction opens a modal built from this same form and persists it
 * with a bare `$record->update($data)`, which never reaches EditUser::afterSave().
 * Roles and is_active would silently do nothing there. The table therefore links
 * rows straight to the full edit page, where the Action hooks run.
 *
 * There are no bulk actions, deliberately. Filament authorizes a bulk action once
 * against the *Any policy method and never consults the per-record method, so a
 * bulk delete could not express "unless this one is a super admin". Delete
 * accounts one at a time, where DeleteUserAction applies.
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return __('staff.user');
    }

    public static function getPluralModelLabel(): string
    {
        return __('staff.users');
    }

    public static function getNavigationLabel(): string
    {
        return __('staff.users');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label(__('staff.name'))
                ->required()
                ->maxLength(255),

            TextInput::make('email')
                ->label(__('staff.email'))
                ->email()
                ->required()
                ->maxLength(255)
                ->unique(ignoreRecord: true),

            Select::make('locale')
                ->label(__('staff.locale'))
                ->options([
                    'en' => __('staff.locale_en'),
                    'ar' => __('staff.locale_ar'),
                ])
                ->default('en')
                ->required(),

            // NOT model-bound: a direct is_active write skips DeactivateUserAction
            // and its last-super-admin invariant, and the architecture test fails
            // the build on it. The pages route the change through the Actions.
            Toggle::make('is_active')
                ->label(__('staff.is_active'))
                ->dehydrated(false)
                ->default(true),

            // NOTE: no ->relationship('roles'). This field is display-only;
            // SyncUserRolesAction performs the write from the page's save hook.
            Select::make('roles')
                ->label(__('staff.roles'))
                ->multiple()
                ->dehydrated(false)
                // At least one role is required. Panel access comes from the
                // access_admin_panel permission, which is granted through a
                // role — so a roleless account is one nobody can ever sign in
                // to, silently created and needing a second edit to fix.
                ->required()
                ->minItems(1)
                // Bounded by the four system-defined roles. Roles are not a
                // user-generated catalogue and do not grow with account count.
                ->options(fn (): array => Role::query()->pluck('name', 'name')->all())
                ->afterStateHydrated(fn (Select $component, ?User $record) => $component->state(
                    $record?->roles()->pluck('name')->all() ?? [],
                )),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('staff.name'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->label(__('staff.email'))
                    ->searchable(),
                TextColumn::make('roles.name')
                    ->label(__('staff.roles'))
                    ->badge(),
                IconColumn::make('is_active')
                    ->label(__('staff.is_active'))
                    ->boolean(),
                TextColumn::make('last_login_at')
                    ->label(__('staff.last_login'))
                    ->dateTime()
                    ->placeholder(__('staff.never'))
                    ->sortable(),
            ])
            ->recordUrl(fn (User $record): string => static::getUrl('edit', ['record' => $record]))
            ->recordActions([
                Action::make('resetPassword')
                    ->label(__('staff.reset_password'))
                    ->icon(Heroicon::OutlinedKey)
                    ->requiresConfirmation()
                    // visible() is UX only — a crafted Livewire call can invoke
                    // a hidden action. ResetUserPasswordAction re-authorizes.
                    ->visible(fn (User $record): bool => auth()->user()?->can('resetPassword', $record) ?? false)
                    ->action(function (User $record): void {
                        /** @var User $actor */
                        $actor = auth()->user();

                        try {
                            $plain = app(ResetUserPasswordAction::class)->execute($actor, $record);
                        } catch (AuthorizationException) {
                            Notification::make()
                                ->title(__('staff.password_reset_refused'))
                                ->danger()
                                ->send();

                            return;
                        }

                        // persistent() is deliberate: the plaintext appears
                        // exactly once and the administrator must dismiss it by
                        // hand. An auto-dismissing toast would lose it.
                        Notification::make()
                            ->title(__('staff.temp_password_generated'))
                            ->body($plain)
                            ->persistent()
                            ->warning()
                            ->send();
                    }),

                // using() replaces DeleteAction's built-in $record->delete() with
                // DeleteUserAction, which authorizes the actor and routes a
                // super-admin target through SuperAdminInvariantService.
                //
                // Filament actions carry NO automatic policy authorization —
                // "Authorization defaults to null (allowed for all users)", per
                // Filament\Actions\Concerns\CanBeAuthorized. The visible() below
                // is therefore the UX gate, and DeleteUserAction is the boundary.
                DeleteAction::make()
                    ->visible(fn (User $record): bool => auth()->user()?->can('delete', $record) ?? false)
                    ->using(function (User $record): bool {
                        /** @var User $actor */
                        $actor = auth()->user();

                        try {
                            app(DeleteUserAction::class)->execute($actor, $record);
                        } catch (AuthorizationException) {
                            Notification::make()
                                ->title(__('staff.delete_refused_unauthorized'))
                                ->danger()
                                ->send();

                            return false;
                        } catch (LastSuperAdminException) {
                            Notification::make()
                                ->title(__('staff.delete_refused_last_super_admin'))
                                ->danger()
                                ->send();

                            return false;
                        }

                        return true;
                    }),
            ])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
