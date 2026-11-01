<?php

declare(strict_types=1);

namespace App\Domain\Staff\Filament\Resources\RoleResource\Pages;

use App\Domain\Staff\Actions\UpdateRolePermissionsAction;
use App\Domain\Staff\Filament\Resources\RoleResource;
use App\Domain\Staff\Filament\Resources\RoleResource\PermissionGrid;
use App\Models\Role;
use App\Models\User;
use BezhanSalleh\FilamentShield\Resources\Roles\Pages\CreateRole as ShieldCreateRole;
use BezhanSalleh\FilamentShield\Support\Utils;
use Spatie\Permission\Models\Permission;

/**
 * As with EditRole, the permission write on create is routed through
 * UpdateRolePermissionsAction rather than Shield's direct
 * Role::syncPermissions() call. A second super_admin role cannot be created —
 * the (name, guard_name) unique index on `roles` refuses it before this hook
 * runs.
 *
 * The role is created with exactly the ticked boxes: a new role holds nothing
 * hidden, so there is nothing to carry through.
 */
class CreateRole extends ShieldCreateRole
{
    protected static string $resource = RoleResource::class;

    /**
     * The role row gets its name and the panel's own guard; the ticked boxes are kept for afterCreate().
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->permissions = collect(PermissionGrid::tickedNames((array) ($data[PermissionGrid::STATE] ?? [])));

        return [
            'name' => $data['name'],
            'guard_name' => Utils::getFilamentAuthGuard(),
        ];
    }

    protected function afterCreate(): void
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
     * @return array<int, string>
     */
    private function resolvePermissionNames(Role $record): array
    {
        return collect(PermissionGrid::namesToSave(null, $this->permissions->all()))
            ->map(fn (string $name): string => Permission::firstOrCreate([
                'name' => $name,
                'guard_name' => $record->guard_name,
            ])->name)
            ->values()
            ->all();
    }
}
