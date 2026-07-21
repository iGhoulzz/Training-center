<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

/**
 * Architecture enforcement for the Action write boundary (P1-T04c).
 *
 * These tests make the boundary self-policing: application code under app/ may
 * NOT reach around the Actions to write roles or permissions through raw Spatie
 * or Eloquent APIs. Each rule keeps a narrow, explicit allowlist for the
 * sanctioned Actions and the trusted system-setup path.
 *
 * Pest's arch() cannot express "this method is called anywhere in a file", so
 * the call rules scan source directly. Comments and docblocks are stripped via
 * the tokenizer first, so a method named in a docblock never trips a rule — the
 * scan only sees real code.
 *
 * PROVEN TO CATCH VIOLATIONS: adding any forbidden call (e.g. a bare
 * `$user->assignRole('admin')` in a controller) makes the corresponding rule
 * list that file and fail. This was demonstrated during P1-T04c verification.
 */

/**
 * @return array<int, string> Absolute paths of every PHP file under app/.
 */
function appSourceFiles(): array
{
    return collect(File::allFiles(app_path()))
        ->filter(fn ($file): bool => $file->getExtension() === 'php')
        ->map(fn ($file): string => (string) $file->getRealPath())
        ->values()
        ->all();
}

/**
 * The file's PHP source with all comments and docblocks removed, so scans see
 * code only.
 */
function appSourceWithoutComments(string $path): string
{
    $code = '';

    foreach (token_get_all((string) file_get_contents($path)) as $token) {
        if (is_array($token)) {
            if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= $token[1];

            continue;
        }

        $code .= $token;
    }

    return $code;
}

/**
 * Files under app/ whose stripped source matches $pattern, excluding any file
 * whose basename (without .php) is in $allowedClasses.
 *
 * @param  array<int, string>  $allowedClasses
 * @return array<int, string> Repo-relative paths of the offenders.
 */
function filesMatching(string $pattern, array $allowedClasses = []): array
{
    $offenders = [];

    foreach (appSourceFiles() as $path) {
        if (in_array(basename($path, '.php'), $allowedClasses, true)) {
            continue;
        }

        if (preg_match($pattern, appSourceWithoutComments($path)) === 1) {
            $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
        }
    }

    return $offenders;
}

it('does not import the vendor Spatie Role model outside App\\Models\\Role', function () {
    // App\Models\Role is the one class allowed to extend/import the vendor Role;
    // everything else must resolve roles through App\Models\Role.
    $offenders = filesMatching('/\bSpatie\\\\Permission\\\\Models\\\\Role\b/', ['Role']);

    expect($offenders)->toBeEmpty(
        'Application code must use App\Models\Role, not the vendor model: '.implode(', ', $offenders),
    );
});

it('does not call assignRole/removeRole/syncRoles outside the sanctioned Actions', function () {
    $offenders = filesMatching(
        '/->\s*(assignRole|removeRole|syncRoles)\s*\(/',
        ['SyncUserRolesAction', 'SystemRoleWriter'],
    );

    expect($offenders)->toBeEmpty(
        'Role assignment must go through SyncUserRolesAction (request path) or SystemRoleWriter (system setup): '.implode(', ', $offenders),
    );
});

it('does not call the Spatie role-side pivot helpers anywhere in app', function () {
    // assignToModels/removeFromModels/syncModels write the pivot from the role
    // side, never touching the user Action path. No application code may use
    // them at all.
    $offenders = filesMatching('/->\s*(assignToModels|removeFromModels|syncModels)\s*\(/');

    expect($offenders)->toBeEmpty(
        'The Spatie role-side pivot helpers are forbidden in application code: '.implode(', ', $offenders),
    );
});

it('does not write role/permission/user relations directly via attach/detach/sync', function () {
    // Filament's Select::relationship('roles') and any hand-written
    // $user->roles()->sync(...) bypass every guard. Forbidden in app code.
    $offenders = filesMatching(
        '/->\s*(roles|permissions|users)\s*\(\s*\)\s*->\s*(attach|detach|sync|syncWithoutDetaching|toggle)\s*\(/',
    );

    expect($offenders)->toBeEmpty(
        'Direct role/permission/user relationship writes are forbidden; route through an Action: '.implode(', ', $offenders),
    );
});

it('does not change role or user permissions outside UpdateRolePermissionsAction', function () {
    $offenders = filesMatching(
        '/->\s*(syncPermissions|givePermissionTo|revokePermissionTo)\s*\(/',
        ['UpdateRolePermissionsAction', 'SystemRoleWriter'],
    );

    expect($offenders)->toBeEmpty(
        'Permission writes must go through UpdateRolePermissionsAction (request path) or SystemRoleWriter (system setup): '.implode(', ', $offenders),
    );
});

it('keeps no write-guard method overrides on the User model', function () {
    // The whole point of P1-T04c: the model holds configuration, not guarded
    // write overrides. None of these method declarations may reappear.
    $code = appSourceWithoutComments(app_path('Models/User.php'));

    foreach (['assignRole', 'removeRole', 'syncRoles', 'givePermissionTo', 'revokePermissionTo', 'syncPermissions', 'forceDelete', 'booted'] as $method) {
        expect(preg_match('/function\s+'.$method.'\s*\(/', $code))->toBe(
            0,
            "User must not override {$method}(); actor-aware writes belong in Actions.",
        );
    }
});

it('keeps no write-guard method overrides on the Role model', function () {
    $code = appSourceWithoutComments(app_path('Models/Role.php'));

    foreach (['syncPermissions', 'revokePermissionTo', 'removeFromModels', 'syncModels', 'booted'] as $method) {
        expect(preg_match('/function\s+'.$method.'\s*\(/', $code))->toBe(
            0,
            "Role must not override {$method}(); protection belongs in RolePolicy and Actions.",
        );
    }
});
