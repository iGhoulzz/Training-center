<?php

declare(strict_types=1);

namespace App\Domain\Staff\Filament\Resources\RoleResource\Pages;

use App\Domain\Staff\Filament\Resources\RoleResource;
use App\Domain\Staff\Filament\Resources\RoleResource\PermissionGrid;
use App\Models\Role;
use BezhanSalleh\FilamentShield\Resources\Roles\Pages\ViewRole as ShieldViewRole;

/**
 * The same permission grid as the editor, read-only: the boxes the role holds are ticked and
 * none can be changed. This is the one View page that is a disabled form on purpose, because
 * the grid IS the page; AdminDetailsArchTest lists it as the exception.
 */
class ViewRole extends ShieldViewRole
{
    protected static string $resource = RoleResource::class;

    /**
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
}
