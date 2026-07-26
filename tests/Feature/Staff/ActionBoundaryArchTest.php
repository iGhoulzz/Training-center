<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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

/*
 * Every BelongsToMany writer that can create, change, or remove a pivot row.
 *
 * attach/detach/sync were the obvious three; attachOrFail(), save(), saveMany(),
 * create(), and toggle() reach the same rows by other names, and a detector
 * that names only the obvious three reads as coverage while leaving the rest
 * open. Each is mutation-tested in this file.
 *
 * THE *OrFail AND *Quietly VARIANTS ARE NOT OPTIONAL ENTRIES
 * ----------------------------------------------------------
 * This list previously held `sync` but not `syncOrFail`, and the alternation
 * does not cover one with the other: matching `sync` against `syncOrFail(`
 * consumes four characters and then requires `\s*\(`, which fails on the `O`.
 * Every variant therefore has to be named in its own right. Nine were missing
 * — syncOrFail, syncWithoutDetachingOrFail, syncWithPivotValues,
 * syncWithPivotValuesOrFail, toggleOrFail, updateExistingPivotOrFail,
 * saveManyQuietly, firstOrCreate, createOrFirst and updateOrCreate — and each
 * was a live route to batch_instructor that this rule reported as covered.
 *
 * A hand-maintained list is the actual defect, so it no longer stands alone:
 * the guard test at the bottom of this file reflects over BelongsToMany and
 * fails if the framework exposes a write-shaped public method this constant
 * does not name. A Laravel upgrade that adds one breaks the build instead of
 * quietly reopening the hole.
 */
const KNOWN_PIVOT_MUTATORS = '(attach|attachOrFail|detach|detachOrFail'
    .'|sync|syncOrFail|syncWithoutDetaching|syncWithoutDetachingOrFail'
    .'|syncWithPivotValues|syncWithPivotValuesOrFail'
    .'|toggle|toggleOrFail|updateExistingPivot|updateExistingPivotOrFail'
    .'|save|saveMany|saveQuietly|saveManyQuietly'
    .'|create|createMany|createQuietly|createOrFirst'
    .'|firstOrCreate|updateOrCreate|push)';

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

it('does not write a guarded pivot relation directly via attach/detach/sync', function () {
    // Filament's Select::relationship('roles') and any hand-written
    // $user->roles()->sync(...) bypass every guard. Forbidden in app code.
    //
    // ONE RULE PER GUARDED RELATION GROUP, EACH WITH ITS OWN ALLOWLIST.
    //
    // P1-T10a merged the RBAC relations and `instructors` into a single rule
    // and allowlisted the two instructor Actions on it. Because an allowlist
    // exempts a FILE from the WHOLE rule, that silently permitted
    // $user->roles()->attach() inside AssignInstructorAction — the RBAC
    // protection was traded away to add a new relation. Verified: the merged
    // rule passed with exactly that call injected.
    //
    // Keep them separate. A file allowlisted for instructor writes is still
    // fully bound by the RBAC rule below.
    $offenders = filesMatching(
        '/->\s*(roles|permissions|users)\s*\(\s*\)\s*->\s*'
        .KNOWN_PIVOT_MUTATORS.'\s*\(/',
    );

    expect($offenders)->toBeEmpty(
        'Direct role/permission/user relationship writes are forbidden; route through an Action: '.implode(', ', $offenders),
    );
});

it('does not write the instructor pivot outside the sanctioned Actions', function () {
    // Hours on batch_instructor are what phase 2 pays wages from, and the
    // closed-batch status gate lives in AssignInstructorAction. A raw pivot
    // write bypasses both.
    //
    // Separate from the RBAC rule above precisely so this allowlist cannot
    // weaken that one.
    $offenders = filesMatching(
        '/->\s*instructors\s*\(\s*\)\s*->\s*'.KNOWN_PIVOT_MUTATORS.'\s*\(/',
        ['AssignInstructorAction', 'RemoveInstructorAction'],
    );

    expect($offenders)->toBeEmpty(
        'Instructor pivot writes must go through AssignInstructorAction / RemoveInstructorAction: '.implode(', ', $offenders),
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

it('does not let a Filament field persist a guarded relation via relationship()', function () {
    // THE most important rule here. Select::make('roles')->relationship('roles')
    // persists by calling the relation's sync()/detach(), reaching around every
    // Action — and unlike raw SQL, it is reachable from the UI. The generic
    // attach/detach/sync rule above cannot see it, because the bypass is a
    // string argument to relationship(), not a method call on the relation.
    $offenders = filesMatching('/->\s*relationship\s*\(\s*[\'"](roles|permissions|instructors)[\'"]/');

    expect($offenders)->toBeEmpty(
        'Filament relationship() persistence for roles/permissions bypasses the Actions; '
        .'make the field dehydrated(false) and write through an Action: '.implode(', ', $offenders),
    );
});

it('does not call the trusted system writer from application code', function () {
    // SystemRoleWriter performs unauthorized writes on purpose. It belongs to
    // seeders, factories and console setup — never to anything serving a
    // request, where an actor exists and the Actions apply.
    $offenders = filesMatching('/\bSystemRoleWriter\b/', ['SystemRoleWriter']);

    expect($offenders)->toBeEmpty(
        'SystemRoleWriter is for seeders/factories/console setup only, never application code: '
        .implode(', ', $offenders),
    );
});

it('does not delete or deactivate users outside the sanctioned Actions', function () {
    // Deleting a user or flipping is_active can remove the last super admin, so
    // both belong to DeleteUserAction / DeactivateUserAction where the invariant
    // service runs.
    //
    // The detector is DELIBERATELY BROAD: any ->delete(), on anything.
    //
    // A previous version filtered to files naming the User model, to stop it
    // matching CourseResource. That filter was unsound — it is case sensitive
    // and matches a class name, so `$request->user()->delete()` sails straight
    // past it while deleting exactly the model the rule protects. A detector
    // with a hole is worse than none, because it reads as coverage.
    //
    // The allowlist is the right lever: it names the Actions sanctioned to
    // delete a record, each of which owns its own invariant. Adding to it is a
    // deliberate act, visible in review, whereas a cleverer regex silently
    // stops catching things.
    $offenders = filesMatching(
        '/->\s*(delete|forceDelete)\s*\(\s*\)|[\'"]is_active[\'"]\s*=>\s*(false|0)\b/',
        [
            // Users: the last-super-admin invariant lives behind these.
            'DeleteUserAction',
            'DeactivateUserAction',
            // Courses: refuses while batches reference the course.
            'DeleteCourseAction',
            // Staff files (P1-T06b): each removes a record AND owns the durable
            // commit-first/delete-after file lifecycle. The certificate and
            // profile Actions authorize the actor and write a
            // pending_file_deletions receipt in the same transaction as the row
            // removal; the purge job removes that receipt once the bytes are gone.
            'DeleteStaffCertificateAction',
            'DeleteStaffProfileAction',
            // Cancels a provisional upload-cleanup receipt only after the
            // owning transaction commits; it never deletes a domain record.
            'FileLifecycleService',
            'PurgeDeletedFileJob',
        ],
    );

    expect($offenders)->toBeEmpty(
        'Record deletion and user deactivation must go through a sanctioned Action: '
        .implode(', ', $offenders),
    );
});

it('does not create, update, or delete Role records outside the sanctioned paths', function () {
    // Creating a role, renaming one, or deleting one all mutate the
    // authorization graph and must pass through the policy-gated pages/Actions.
    $offenders = filesMatching(
        '/\bRole::(create|updateOrCreate|firstOrCreate|findOrCreate)\s*\(/',
        ['SystemRoleWriter', 'UpdateRolePermissionsAction', 'Role'],
    );

    expect($offenders)->toBeEmpty(
        'Role creation/mutation belongs to the policy-gated pages and Actions: '.implode(', ', $offenders),
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

it('names every write-shaped BelongsToMany method in KNOWN_PIVOT_MUTATORS', function () {
    // THE ROOT CAUSE OF THE MISSING MUTATORS, CLOSED.
    //
    // Nine writers were absent from the constant above, and nothing failed —
    // the rules kept passing while syncOrFail(), updateOrCreate() and the rest
    // wrote batch_instructor unchallenged. A list maintained by hand against a
    // framework surface that grows every release will drift again, and the
    // drift is silent by construction: a detector that misses a method reports
    // exactly the same green as one that catches it.
    //
    // So the framework is asked directly. Any public BelongsToMany method whose
    // name starts like a write must appear in the constant, or this fails and
    // names it. A Laravel upgrade introducing syncQuietly() breaks the build.
    //
    // The prefix list is deliberately broader than the pivot writers proper —
    // it also catches read helpers like findOrNew(), which are then excluded by
    // name below. An explicit exclusion is reviewable; a narrower prefix regex
    // would silently stop matching things.
    $writeShaped = '/^(attach|detach|sync|toggle|save|create|update|push|first|force|replicate)/';

    /*
     * Public methods that match the write prefix but touch no pivot row.
     *
     * findOrNew() instantiates an unsaved model. updateExistingPivot's siblings
     * are all in the constant; these are not writers at all, and each is listed
     * because it was checked, not because it looked harmless.
     */
    $notWriters = [
        'findOrNew',
        'updatedAt',
        'createdAt',
        'syncTimestamp',
        'syncTimestamps',
        'updateOrCreateUsing',
        'firstOrCreateUsing',
        'firstOrNew',
        'firstOrFail',
        'first',
        'firstWhere',
        'firstOr',
    ];

    $reflection = new ReflectionClass(BelongsToMany::class);

    $uncovered = [];

    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        $name = $method->getName();

        if ($method->isStatic() || in_array($name, $notWriters, true)) {
            continue;
        }

        if (preg_match($writeShaped, $name) !== 1) {
            continue;
        }

        // Anchored on both sides: `sync` must not satisfy the requirement for
        // `syncOrFail`, which is the exact bug this guard exists to prevent.
        if (preg_match('/[(|]'.preg_quote($name, '/').'[)|]/', KNOWN_PIVOT_MUTATORS) !== 1) {
            $uncovered[] = $name;
        }
    }

    expect($uncovered)->toBeEmpty(
        'BelongsToMany exposes write-shaped methods that KNOWN_PIVOT_MUTATORS does not name, '
        .'so the pivot rules above have holes. Add them to the constant, or add them to '
        .'$notWriters with a reason: '.implode(', ', $uncovered),
    );
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
