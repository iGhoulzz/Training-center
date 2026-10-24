<?php

declare(strict_types=1);

namespace App\Domain\Staff\Filament\Resources;

use App\Domain\Finance\Filament\Resources\StaffCompensationResource;
use App\Domain\Staff\Actions\DeleteUserAction;
use App\Domain\Staff\Actions\ResetUserPasswordAction;
use App\Domain\Staff\Exceptions\LastSuperAdminException;
use App\Domain\Staff\Filament\Resources\UserResource\Pages\CreateUser;
use App\Domain\Staff\Filament\Resources\UserResource\Pages\EditUser;
// App\Models\Role, never the vendor model — the architecture test fails the
// build on a Spatie\Permission\Models\Role import outside app/Models/Role.php.
use App\Domain\Staff\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\Role;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Layout\Grid;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

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
 * The role dropdown offers only the roles the actor may assign (P4-T33: it used
 * to offer every role and let the save refuse the ones the actor could not give),
 * and `visible()` on the record actions is a UX affordance only. A crafted
 * Livewire payload can submit a role the dropdown never rendered and can invoke a
 * hidden action. Every one of these paths therefore re-authorizes inside its
 * Action on execute; that is the boundary, and UserResourceTest drives the real
 * component to prove it. The dropdown asks the SAME ability the Action does
 * (UserPolicy::assignRole), so what it offers and what the save accepts cannot
 * drift apart. A Select also validates its state against its options, so a role
 * it did not offer is now turned back by the form itself before the Action is
 * reached; that is an extra layer, not a replacement for the Action.
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
            Section::make(__('staff.account_details'))->columns(2)->columnSpanFull()->schema([
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
                //
                // Disabled on one's own record (P4-T33): nobody deactivates themself.
                // Disabled is the UX half only - a forged payload can still set the
                // state, and DeactivateUserAction is what refuses it.
                Toggle::make('is_active')
                    ->label(__('staff.is_active'))
                    ->dehydrated(false)
                    ->default(true)
                    ->disabled(fn (?User $record): bool => self::isOwnRecord($record))
                    ->helperText(fn (?User $record): ?string => self::isOwnRecord($record)
                        ? __('staff.is_active_own_account_hint')
                        : null),

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
                    ->options(fn (?User $record): array => self::assignableRoleOptions($record))
                    ->afterStateHydrated(fn (Select $component, ?User $record) => $component->state(
                        $record?->roles()->pluck('name')->all() ?? [],
                    )),
            ]),
        ]);
    }

    /**
     * Is this the account the signed-in person is using?
     */
    private static function isOwnRecord(?User $record): bool
    {
        return $record instanceof User && $record->is(auth()->user());
    }

    /**
     * The roles the signed-in actor may put on an account, plus the ones the
     * edited account already holds.
     *
     * Each role is asked of UserPolicy::assignRole - the ability SyncUserRolesAction
     * authorizes - so the picker offers exactly what a save would accept: super_admin
     * only to a super admin, nothing at all without assign_role, and never `student`
     * (portal accounts are issued by IssuePortalCredentialAction).
     *
     * THE HELD ROLES STAY LISTED. A portal account is an ordinary user row that
     * holds `student`, and the Users list shows it. The form's own state for that
     * account contains `student`; a Select validates its state against its options,
     * so leaving it out would reject every edit of the account, including a rename
     * that touches no role. Listing a role already held offers nothing new: a save
     * that leaves the set alone is a no-op in SyncUserRolesAction.
     *
     * Bounded by the system-defined roles. Roles are not a user-generated
     * catalogue and do not grow with account count.
     *
     * @return array<string, string>
     */
    private static function assignableRoleOptions(?User $record): array
    {
        $actor = auth()->user();
        $held = $record?->roles()->pluck('name')->all() ?? [];

        return Role::query()
            ->orderBy('name')
            ->get()
            ->filter(fn (Role $role): bool => in_array($role->name, $held, true)
                || ($actor?->can('assignRole', [User::class, $role]) ?? false))
            ->pluck('name', 'name')
            ->all();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with([
                'roles',
                ...(auth()->user()?->can('view_staff_profile') ? ['staffProfile'] : []),
            ]))
            ->extraAttributes(['class' => '[&_.fi-ta-record-content-ctn]:py-0! [&_.fi-ta-record-content]:px-0!'])
            ->columns([
                Grid::make(['default' => 1, 'md' => 4])->extraAttributes([
                    'class' => 'gap-3.5 px-4.5 py-[15px] items-center md:grid-cols-[minmax(140px,1.6fr)_minmax(104px,1.1fr)_minmax(96px,1fr)_minmax(168px,1.1fr)] [&>*]:min-w-0',
                ])->schema([
                    Stack::make([
                        ViewColumn::make('identity')->view('filament.enrollment.columns.person')
                            ->label(__('staff.name'))
                            ->searchable(['name', 'email'])
                            ->sortable(['name'])
                            ->viewData(fn (User $record): array => [
                                'name' => $record->name, 'identity' => $record->id, 'size' => 'instructor',
                                'url' => static::canEdit($record) ? static::getUrl('edit', ['record' => $record]) : null,
                                'secondary' => auth()->user()?->can('view_staff_profile') && $record->staffProfile !== null
                                    ? __('staff.profile_summary', ['job' => $record->staffProfile->job_title ?? __('staff.no_job_title'), 'type' => $record->staffProfile->employment_type->label()])
                                    : $record->email,
                            ]),
                    ])->space(1),
                    TextColumn::make('roles.name')
                        ->label(__('staff.roles'))
                        ->badge()->color('gray')
                        ->extraAttributes(['class' => '[&_.fi-badge]:bg-no-portal/15 [&_.fi-badge]:text-no-portal [&_.fi-badge]:ring-0']),
                    Stack::make([
                        TextColumn::make('last_login_at')
                            ->label(__('staff.last_login'))
                            ->dateTime()
                            ->placeholder(__('staff.never'))
                            ->sortable()->size('xs')->color('gray'),
                        TextColumn::make('staffProfile.hire_date')
                            ->label(__('staff.hire_date'))->date()->size('xs')->color('gray')
                            ->visible(fn (): bool => auth()->user()?->can('view_staff_profile') ?? false),
                    ])->space(1),
                    Stack::make([
                        TextColumn::make('is_active')->label(__('staff.is_active'))->badge()
                            ->formatStateUsing(fn (bool $state): string => $state ? __('staff.active') : __('staff.deactivated'))
                            ->color(fn (bool $state): string => $state ? 'success' : 'gray'),
                        Split::make([
                            TextColumn::make('activity_link')->label(__('staff.activity_link'))->state(__('staff.activity_link'))
                                ->url(fn (User $record): string => ActivityResource::getUrl('index', ['tableFilters' => ['causer_id' => ['value' => $record->id]]]))
                                ->visible(fn (): bool => auth()->user()?->can('view_any_activity') ?? false)
                                ->size('xs')->color('gray')->grow(false),
                            TextColumn::make('payroll_link')->label(__('staff.payroll_link'))->state(__('staff.payroll_link'))
                                ->url(fn (User $record): string => StaffCompensationResource::getUrl('index', ['tableSearch' => $record->name]))
                                ->visible(fn (): bool => auth()->user()?->can('view_any_staff_compensation') ?? false)
                                ->size('xs')->color('gray')->grow(false),
                        ])->extraAttributes(['class' => 'gap-1.5 [&_a]:rounded-[7px] [&_a]:bg-fill-subtle [&_a]:px-[9px] [&_a]:py-1 [&_a]:ring-1 [&_a]:ring-control [&_a]:hover:text-primary-400']),
                    ])->space(1),
                ]),
            ])
            ->recordUrl(null)
            ->recordActions([
                ActionGroup::make([
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
                ]),
            ])
            ->recordActionsAlignment('start')
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
