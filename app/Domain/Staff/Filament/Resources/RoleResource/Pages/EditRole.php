<?php

declare(strict_types=1);

namespace App\Domain\Staff\Filament\Resources\RoleResource\Pages;

use App\Domain\Staff\Actions\UpdateRolePermissionsAction;
use App\Domain\Staff\Filament\Resources\RoleResource;
use App\Models\Role;
use App\Models\User;
use BezhanSalleh\FilamentShield\Resources\Roles\Pages\EditRole as ShieldEditRole;
use Spatie\Permission\Models\Permission;

/**
 * Reuses Shield's edit form and its permission-collection logic, but redirects
 * the write: instead of Shield's afterSave() calling Role::syncPermissions()
 * directly, the selected permissions are handed to UpdateRolePermissionsAction,
 * which authorizes via RolePolicy (the super_admin role and any role the actor
 * holds are refused before this page is ever reachable).
 */
class EditRole extends ShieldEditRole
{
    protected static string $resource = RoleResource::class;

    protected function afterSave(): void
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
     * The parent's mutateFormDataBeforeSave() populated $this->permissions with
     * the selected permission names. Mirror Shield's firstOrCreate so a name the
     * form surfaced but the seeder has not created yet cannot make the sync
     * throw, then return the resolved names for the action.
     *
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
