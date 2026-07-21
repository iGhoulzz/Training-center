<?php

declare(strict_types=1);

namespace App\Domain\Staff\Filament\Resources;

use App\Domain\Staff\Filament\Resources\RoleResource\Pages\CreateRole;
use App\Domain\Staff\Filament\Resources\RoleResource\Pages\EditRole;
use App\Domain\Staff\Filament\Resources\RoleResource\Pages\ListRoles;
use App\Domain\Staff\Filament\Resources\RoleResource\Pages\ViewRole;
use BezhanSalleh\FilamentShield\Resources\Roles\RoleResource as ShieldRoleResource;

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
 * Everything else — the form, the table, the model, the slug, the navigation —
 * is inherited unchanged from Shield.
 *
 * Because this class name ends in "RoleResource", FilamentShieldPlugin's
 * isResourcePublished() check treats the role resource as published and does
 * NOT register its own, so there is exactly one role resource on the panel.
 */
class RoleResource extends ShieldRoleResource
{
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
