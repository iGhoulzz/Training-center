<?php

declare(strict_types=1);

namespace App\Domain\Staff\Filament\Resources;

use App\Domain\Staff\Filament\Resources\RoleResource\Pages\CreateRole;
use App\Domain\Staff\Filament\Resources\RoleResource\Pages\EditRole;
use App\Domain\Staff\Filament\Resources\RoleResource\Pages\ListRoles;
use App\Domain\Staff\Filament\Resources\RoleResource\Pages\ViewRole;
use App\Models\Role;
use BezhanSalleh\FilamentShield\Facades\FilamentShield;
use BezhanSalleh\FilamentShield\Resources\Roles\RoleResource as ShieldRoleResource;
use BezhanSalleh\FilamentShield\Support\Utils;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;

/**
 * Application-owned role resource (P1-T04c).
 *
 * WHY THIS EXISTS
 * ---------------
 * Shield's built-in role pages write the permission set by calling
 * Role::syncPermissions() directly in their afterSave()/afterCreate() hooks.
 * With the Role model's write guards removed, that is an unauthorized,
 * unprotected mutation surface — exactly the "Shield editor silently strips
 * super_admin permissions" bypass (finding 2).
 *
 * Rather than depend on model-event overrides or hidden fields, this resource
 * replaces Shield's role pages with app-owned ones that route every permission
 * write through UpdateRolePermissionsAction, which authorizes via RolePolicy.
 * The model, the slug and the navigation are inherited unchanged from Shield.
 * The table is inherited except for its write surfaces (see table()), and the
 * form is inherited except for its custom-permissions tab (see
 * getTabFormComponentForCustomPermissions()), which is overridden for two
 * reasons: its labels come from staff.permissions.*, and every permission the
 * seeder creates must be tickable, because a save syncs a role to exactly the
 * boxes ticked and would otherwise strip any that has none (P4-T21).
 *
 * Because this class name ends in "RoleResource", FilamentShieldPlugin's
 * isResourcePublished() check treats the role resource as published and does
 * NOT register its own, so there is exactly one role resource on the panel.
 */
class RoleResource extends ShieldRoleResource
{
    public static function form(Schema $schema): Schema
    {
        return parent::form($schema)->extraAttributes(['class' => 'gap-4 [&_.fi-section-content]:p-4.5 [&_.fi-fo-field-label-content]:text-xs']);
    }

    /**
     * Shield's table is inherited except for its write surfaces (P1-T15,
     * security review finding 3).
     *
     * The docblock above says the table is "inherited unchanged". It was — and
     * the table contained a permission-write path that this resource exists to
     * remove. Shield's inline EditAction opens a modal built from the same form
     * and persists it with a bare $record->update($data), which never reaches
     * EditRole::afterSave() and therefore never reaches
     * UpdateRolePermissionsAction, "the single sanctioned path".
     *
     * Worse than a bypass, it was a silent one. The modal fills from
     * $record->attributesToArray(), which returns only the roles table columns,
     * so the permission matrix rendered entirely unchecked whatever the role
     * actually held. An administrator would see an empty grid, save, and be told
     * it worked — with no permission change written, and no permissions_changed
     * entry in the activity log, because Role::auditedAttributes() covers only
     * name and guard_name.
     *
     * UserResource diagnosed this exact hazard and declined to inherit it. This
     * resource now does the same: edit is a LINK to the app-owned page, so every
     * permission write goes through the Action and lands in the audit trail.
     *
     * The inherited DeleteBulkAction goes too. RolePolicy::deleteAny() already
     * refuses it, so it rendered only in order to fail — but a control that
     * works solely because a policy says no is one policy edit from working.
     */
    public static function table(Table $table): Table
    {
        return parent::table($table)
            ->extraAttributes(['class' => '[&_.fi-ta-record-content-ctn]:py-0! [&_.fi-ta-record-content]:px-0!'])
            ->columns([
                Split::make([
                    Stack::make([
                        TextColumn::make('name')->label(__('staff.roles'))->searchable()->weight('semibold'),
                        TextColumn::make('guard_name')->label(__('staff.guard'))->size('xs')->color('gray'),
                    ])->space(1),
                    TextColumn::make('permissions_count')->label(__('staff.role_details'))->counts('permissions')->badge()->color('info'),
                    TextColumn::make('updated_at')->label(__('staff.updated_at'))->dateTime()->size('xs')->color('gray'),
                ])->from('md')->extraAttributes(['class' => 'gap-3.5 px-4.5 py-[15px]']),
            ])
            ->recordActions([
                Action::make('edit')
                    ->label(__('filament-actions::edit.single.label'))
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->url(fn (Role $record): string => EditRole::getUrl(['record' => $record]))
                    ->authorize(fn (Role $record): bool => Gate::allows('update', $record)),
                DeleteAction::make(),
            ])
            ->toolbarActions([]);
    }

    /**
     * The tab for the permissions no resource, page or widget tab offers
     * (P4-T21), with labels from the translation files.
     *
     * WHY THE TAB MUST EXIST
     * ----------------------
     * EditRole and CreateRole sync a role to exactly the boxes the form shows. A
     * permission with no box is removed by the next save, so before this tab
     * saving staff, admin or student with nothing changed removed
     * access_admin_panel and access_student_portal from all three, and a role
     * created in the editor could never be given panel access.
     *
     * WHY THIS OVERRIDES SHIELD'S
     * ---------------------------
     * Shield builds the labels itself: headline() of the key, or, with its
     * localization switched on, its own lang files. The first is hardcoded
     * English derived from a code identifier, and the second needs the panel
     * provider, which this task does not touch. So the options are built here
     * from the configured names, each label from `staff.permissions.<name>`, and
     * the checkbox list is Shield's own: the edit form still ticks what the role
     * holds, "select all" still counts this tab, and EditRole's data mutation
     * still collects the field with no other change.
     *
     * The names come from config/filament-shield.php; the list there says which
     * permissions belong and how it was worked out.
     */
    public static function getTabFormComponentForCustomPermissions(): Component
    {
        $options = collect(array_keys(FilamentShield::getCustomPermissions() ?? []))
            ->mapWithKeys(fn (string $name): array => [$name => __("staff.permissions.{$name}")])
            ->all();
        $count = count($options);

        return Tab::make('custom_permissions')
            ->label(__('filament-shield::filament-shield.custom'))
            ->visible(fn (): bool => Utils::isCustomPermissionTabEnabled() && $count > 0)
            ->badge($count)
            ->schema([
                static::getCheckboxListFormComponent(
                    name: 'custom_permissions_tab',
                    options: $options,
                ),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRoles::route('/'),
            'create' => CreateRole::route('/create'),
            'view' => ViewRole::route('/{record}'),
            'edit' => EditRole::route('/{record}/edit'),
        ];
    }
}
