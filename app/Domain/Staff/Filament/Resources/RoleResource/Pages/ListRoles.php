<?php

declare(strict_types=1);

namespace App\Domain\Staff\Filament\Resources\RoleResource\Pages;

use App\Domain\Staff\Filament\Resources\RoleResource;
use BezhanSalleh\FilamentShield\Resources\Roles\Pages\ListRoles as ShieldListRoles;

class ListRoles extends ShieldListRoles
{
    protected static string $resource = RoleResource::class;
}
