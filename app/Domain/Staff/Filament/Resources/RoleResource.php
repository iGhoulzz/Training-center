<?php

declare(strict_types=1);

namespace App\Domain\Staff\Filament\Resources;

use App\Domain\Staff\Filament\Resources\RoleResource\Pages\CreateRole;
use App\Domain\Staff\Filament\Resources\RoleResource\Pages\EditRole;
use App\Domain\Staff\Filament\Resources\RoleResource\Pages\ListRoles;
use App\Domain\Staff\Filament\Resources\RoleResource\Pages\ViewRole;
use App\Domain\Staff\Filament\Resources\RoleResource\PermissionGrid;
use App\Filament\Tables\AdminColumns;
use App\Filament\Tables\AdminTable;
use App\Models\Role;
use BezhanSalleh\FilamentShield\Resources\Roles\RoleResource as ShieldRoleResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
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
 * The table is inherited except for its write surfaces (see table()).
 *
 * THE FORM IS OURS TOO (P4-T42). Shield's form listed every permission it could
 * generate under the model's class path, with technical labels and boxes for acts
 * the application does not have. form() draws PermissionGrid instead: a row per
 * area, a column per ability, translated labels, and a sentence for each special
 * permission. A save syncs a role to exactly what it is given, so the grid carries
 * through any permission it does not show (see PermissionGrid, which also records
 * why every other box is hidden).
 *
 * Because this class name ends in "RoleResource", FilamentShieldPlugin's
 * isResourcePublished() check treats the role resource as published and does
 * NOT register its own, so there is exactly one role resource on the panel.
 */
class RoleResource extends ShieldRoleResource
{
    /**
     * The role's name and its permission grid.
     *
     * There is no guard field. The guard is the panel's own, set on create by CreateRole
     * and never changed by an edit; showing it let a person retype a technical value that
     * decides which permissions the role can hold.
     */
    public static function form(Schema $schema): Schema
    {
        return $schema->extraAttributes(['class' => 'tc-perms gap-4'])->components([
            Section::make(__('staff.grid.role_section'))->columns(['default' => 1, 'md' => 2])->columnSpanFull()->schema([
                TextInput::make('name')
                    ->label(__('filament-shield::filament-shield.field.name'))
                    ->unique(ignoreRecord: true)
                    ->required()
                    ->maxLength(255),

                PermissionGrid::selectAll(),
            ]),

            ...PermissionGrid::sections(),
        ]);
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
        return AdminTable::configure(parent::table($table))
            ->columns([
                AdminColumns::title('name', __('staff.roles'))
                    ->searchable(),

                AdminColumns::secondary('guard_name', __('staff.guard')),

                TextColumn::make('permissions_count')
                    ->label(__('staff.role_details'))
                    ->counts('permissions')
                    ->badge()
                    ->color('info'),

                AdminColumns::dateTime('updated_at', __('staff.updated_at'))
                    ->toggleable()
                    ->visibleFrom('md'),
            ])
            ->recordActions([
                AdminTable::rowMenu([
                    Action::make('edit')
                        ->label(__('filament-actions::edit.single.label'))
                        ->icon(Heroicon::OutlinedPencilSquare)
                        ->url(fn (Role $record): string => EditRole::getUrl(['record' => $record]))
                        ->authorize(fn (Role $record): bool => Gate::allows('update', $record)),
                    DeleteAction::make(),
                ]),
            ])
            ->toolbarActions([]);
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
