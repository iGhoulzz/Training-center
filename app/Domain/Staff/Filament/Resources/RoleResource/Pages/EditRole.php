<?php

declare(strict_types=1);

namespace App\Domain\Staff\Filament\Resources\RoleResource\Pages;

use App\Domain\Staff\Actions\UpdateRolePermissionsAction;
use App\Domain\Staff\Filament\Resources\RoleResource;
use App\Domain\Staff\Filament\Resources\RoleResource\PermissionGrid;
use App\Models\Role;
use App\Models\User;
use BezhanSalleh\FilamentShield\Resources\Roles\Pages\EditRole as ShieldEditRole;
use Illuminate\Support\Arr;
use Spatie\Permission\Models\Permission;

/**
 * Keeps Shield's page shell, but the form is PermissionGrid's and the write is redirected:
 * instead of Shield's afterSave() calling Role::syncPermissions() directly, the permissions
 * are handed to UpdateRolePermissionsAction, which authorizes via RolePolicy (the super_admin
 * role and any role the actor holds are refused before this page is ever reachable).
 *
 * `$permissions` holds the visible boxes the form has ticked. The permissions the grid does
 * not show are added back at save time (PermissionGrid::namesToSave), because the Action syncs
 * the role to exactly the names it is given.
 */
class EditRole extends ShieldEditRole
{
    protected static string $resource = RoleResource::class;

    /**
     * Starts the grid from what the role holds.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Role $record */
        $record = $this->getRecord();

        $data[PermissionGrid::STATE] = PermissionGrid::stateFor($record);
        $data['select_all'] = PermissionGrid::allTicked($data[PermissionGrid::STATE]);

        return $data;
    }

    /**
     * Only the name is saved on the role row; the ticked boxes are kept for afterSave().
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->permissions = collect(PermissionGrid::tickedNames((array) ($data[PermissionGrid::STATE] ?? [])));

        return Arr::only($data, ['name']);
    }

    protected function afterSave(): void
    {
        /** @var User $actor */
        $actor = auth()->user();

        /** @var Role $record */
        $record = $this->record;

        app(UpdateRolePermissionsAction::class)->execute(
            $actor,
            $record,
            $this->resolvePermissionNames($record),
        );
    }

    /**
     * The ticked boxes plus the permissions the grid does not show that the role holds, resolved
     * to names that exist on the role's own guard. Mirrors Shield's firstOrCreate so a name the
     * form surfaced but the seeder has not created yet cannot make the sync throw.
     *
     * @return array<int, string>
     */
    private function resolvePermissionNames(Role $record): array
    {
        return collect(PermissionGrid::namesToSave($record, $this->permissions->all()))
            ->map(fn (string $name): string => Permission::firstOrCreate([
                'name' => $name,
                'guard_name' => $record->guard_name,
            ])->name)
            ->values()
            ->all();
    }
}
