<?php

declare(strict_types=1);

namespace App\Domain\Staff\Filament\Resources\RoleResource\Pages;

use App\Domain\Staff\Actions\UpdateRolePermissionsAction;
use App\Domain\Staff\Filament\Resources\RoleResource;
use App\Models\Role;
use App\Models\User;
use BezhanSalleh\FilamentShield\Resources\Roles\Pages\CreateRole as ShieldCreateRole;
use Spatie\Permission\Models\Permission;

/**
 * As with EditRole, the permission write on create is routed through
 * UpdateRolePermissionsAction rather than Shield's direct
 * Role::syncPermissions() call. A second super_admin role cannot be created —
 * the (name, guard_name) unique index on `roles` refuses it before this hook
 * runs.
 */
class CreateRole extends ShieldCreateRole
{
    protected static string $resource = RoleResource::class;

    protected function afterCreate(): void
    {
        /** @var User $actor */
        $actor = auth()->user();

        /** @var Role $record */
        $record = $this->record;

        app(UpdateRolePermissionsAction::class)->execute(
            $actor,
            $record,
            $this->resolvePermissionNames(),
        );
    }

    /**
     * @return array<int, string>
     */
    private function resolvePermissionNames(): array
    {
        $guard = is_string($this->data['guard_name'] ?? null)
            ? $this->data['guard_name']
            : (string) config('auth.defaults.guard');

        return $this->permissions
            ->map(fn (string $name): string => Permission::firstOrCreate([
                'name' => $name,
                'guard_name' => $guard,
            ])->name)
            ->values()
            ->all();
    }
}
