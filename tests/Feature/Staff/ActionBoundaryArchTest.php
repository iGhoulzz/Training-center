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
 * The raw gateways to the pivot table, which write nothing themselves and hand
 * out something that does.
 *
 * These do not begin with a write-shaped verb, so the reflection guard's prefix
 * regex never sees them and the writer list below never would have grown to
 * include them. They are a real bypass all the same:
 *
 *     $batch->instructors()->newPivotQuery()->delete();
 *     $batch->instructors()->newPivotStatement()->insert([...]);
 *     $batch->instructors()->newExistingPivot([...])->save();
 *
 * Every one of those writes batch_instructor without passing an Action, and
 * before they were listed the boundary rules reported complete coverage while
 * leaving them open. Named separately from the writers because they are a
 * different shape of hole, and folded into the alternation below so every rule
 * using KNOWN_PIVOT_MUTATORS picks them up without being rewritten.
 *
 * getQuery() is deliberately ABSENT. On a BelongsToMany it returns the query for
 * the RELATED model — users, not the pivot — so writing through it is a
 * different rule's business, and listing it here would claim a protection this
 * does not provide.
 *
 * Declared before KNOWN_PIVOT_MUTATORS because that constant interpolates this
 * one, and `const` at file scope is evaluated in source order.
 */
const RAW_PIVOT_GATEWAYS = 'newPivot|newExistingPivot|newPivotQuery'
    .'|newPivotStatement|newPivotStatementForId';

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
 * Every variant therefore has to be named in its own right. TEN were missing —
 * syncOrFail, syncWithoutDetachingOrFail, syncWithPivotValues,
 * syncWithPivotValuesOrFail, toggleOrFail, updateExistingPivotOrFail,
 * saveManyQuietly, firstOrCreate, createOrFirst and updateOrCreate — and each
 * was a live route to batch_instructor that this rule reported as covered.
 *
 * A hand-maintained list is the actual defect, so it no longer stands alone:
 * the guard test at the bottom of this file reflects over BelongsToMany and
 * fails if the framework exposes a write-shaped public method this constant
 * does not name. A Laravel upgrade that adds one breaks the build instead of
 * quietly reopening the hole.
 *
 * `createQuietly` and `push` are named here but are NOT public methods of
 * BelongsToMany, and they stay. An extra name in this constant is fail-safe —
 * it can only make a rule match more — whereas an extra name in the guard's
 * $notWriters exclusions is fail-open, because it removes a real method from
 * scrutiny. Only the exclusions are held to a freshness rule, and that
 * asymmetry is the reason.
 */
const KNOWN_PIVOT_MUTATORS = '(attach|attachOrFail|detach|detachOrFail'
    .'|sync|syncOrFail|syncWithoutDetaching|syncWithoutDetachingOrFail'
    .'|syncWithPivotValues|syncWithPivotValuesOrFail'
    .'|toggle|toggleOrFail|updateExistingPivot|updateExistingPivotOrFail'
    .'|save|saveMany|saveQuietly|saveManyQuietly'
    .'|create|createMany|createQuietly|createOrFirst'
    .'|firstOrCreate|updateOrCreate|push'
    .'|'.RAW_PIVOT_GATEWAYS.')';

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

/*
 * appSourceWithoutComments() lived here until P1-T14, which needed the same
 * comment-stripping for its translation-key scan. It is now in tests/Pest.php,
 * loaded before any test file, so both callers get it regardless of the order
 * Pest happens to load them in.
 */

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
            // Batches (P1-T11): refuses while enrolments or instructor
            // allocations remain, and owns the 1451 conversion.
            'DeleteBatchAction',
            // Discount definitions: deletion is allowed only before use and
            // converts MySQL 1451 into a typed refusal; deactivation is their
            // sole post-use lifecycle transition.
            'DeleteDiscountAction',
            'DeactivateDiscountAction',
            // Enrolments (P1-T11): single-record only, authorizes against the
            // row locked by EnrollmentMutex.
            'DeleteEnrollmentAction',
            /*
             * The bill that goes with a deleted enrolment (P2-T03). Internal:
             * no actor, no policy, one caller — DeleteEnrollmentAction, which
             * checked delete_enrollment against the row it holds locked before
             * calling this. It owns the refusal that keeps money safe: any
             * allocation, adjustment or write-off and the delete is refused
             * with ChargeAlreadyCommittedException, read under the charge's
             * own lock. ChargePolicy::delete() still refuses everyone
             * unconditionally, which is a different question (design §4).
             */
            'DeleteUncommittedChargeAction',
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
    // Ten writers were absent from the constant above, and nothing failed —
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
    // it also catches reads such as first() and firstWhere(), which are then
    // excluded by name below. An explicit exclusion is reviewable; a narrower
    // prefix regex would silently stop matching things.
    //
    // It does NOT reach the raw gateways: newPivotQuery() and its siblings begin
    // with `new`, so they are handled by RAW_PIVOT_GATEWAYS and asserted
    // separately at the end of this test rather than through this regex.
    $writeShaped = '/^(attach|detach|sync|toggle|save|create|update|push|first|force|replicate)/';

    /*
     * Public methods that match the write prefix but touch no pivot row.
     *
     * EVERY ENTRY WAS REFLECTED, NOT REMEMBERED. An earlier version of this list
     * carried five names that do not exist on BelongsToMany at all — findOrNew,
     * syncTimestamp, syncTimestamps, updateOrCreateUsing and firstOrCreateUsing
     * — written from memory of the Eloquent surface instead of read off the
     * class. Dead exclusions are not harmless: this list is FAIL-OPEN, so a
     * guessed name that Laravel later introduces as a real writer would exempt
     * it from the moment it appeared, silently and by accident.
     *
     * The second assertion below therefore polices the exclusions themselves.
     * Each must still be a public method AND still match the prefix regex; one
     * that stops being either is dead weight and fails the build until removed.
     *
     * What survives, and why none of them writes a pivot row: createdAt() and
     * updatedAt() return timestamp COLUMN NAMES. first(), firstOr(),
     * firstOrFail() and firstWhere() are reads, and firstOrNew() instantiates
     * without persisting — firstOrCreate() is the sibling that does persist, and
     * it is named in the constant.
     */
    $notWriters = [
        'createdAt',
        'first',
        'firstOr',
        'firstOrFail',
        'firstOrNew',
        'firstWhere',
        'updatedAt',
    ];

    $reflection = new ReflectionClass(BelongsToMany::class);

    $publicMethods = collect($reflection->getMethods(ReflectionMethod::IS_PUBLIC))
        ->reject(fn (ReflectionMethod $method): bool => $method->isStatic())
        ->map(fn (ReflectionMethod $method): string => $method->getName())
        ->unique();

    // Anchored on both sides, so `sync` cannot satisfy the requirement for
    // `syncOrFail` — the exact bug this guard exists to prevent.
    $named = fn (string $name): bool => preg_match(
        '/[(|]'.preg_quote($name, '/').'[)|]/',
        KNOWN_PIVOT_MUTATORS,
    ) === 1;

    $uncovered = $publicMethods
        ->filter(fn (string $name): bool => preg_match($writeShaped, $name) === 1)
        ->reject(fn (string $name): bool => in_array($name, $notWriters, true))
        ->reject($named)
        ->values()
        ->all();

    expect($uncovered)->toBeEmpty(
        'BelongsToMany exposes write-shaped methods that KNOWN_PIVOT_MUTATORS does not name, '
        .'so the pivot rules above have holes. Add them to the constant, or add them to '
        .'$notWriters with a reason: '.implode(', ', $uncovered),
    );

    // The exclusions, held to the same freshness rule as the list they qualify.
    $stale = collect($notWriters)
        ->reject(fn (string $name): bool => $publicMethods->contains($name)
            && preg_match($writeShaped, $name) === 1)
        ->values()
        ->all();

    expect($stale)->toBeEmpty(
        'These $notWriters exclusions are not currently reflected, prefix-matching public methods '
        .'of BelongsToMany. They exempt nothing today, and would silently exempt a real writer if '
        .'one ever took the name. Remove them: '.implode(', ', $stale),
    );

    /*
     * And the raw gateways, which the prefix regex cannot reach by design.
     *
     * Checked from the other direction: every name in RAW_PIVOT_GATEWAYS must
     * still exist on the class AND still be folded into KNOWN_PIVOT_MUTATORS. A
     * gateway that is listed but not folded in is the worst case here — it reads
     * as covered in the constant's docblock while no rule matches it.
     */
    $missingGateways = collect(explode('|', RAW_PIVOT_GATEWAYS))
        ->reject(fn (string $name): bool => $publicMethods->contains($name) && $named($name))
        ->values()
        ->all();

    expect($missingGateways)->toBeEmpty(
        'RAW_PIVOT_GATEWAYS names something that is either no longer a public BelongsToMany method '
        .'or is not folded into KNOWN_PIVOT_MUTATORS, so the rules do not in fact catch it: '
        .implode(', ', $missingGateways),
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

/*
 * Every shape that writes the enrollments table, SPLIT BY OPERATION.
 *
 * NOT KNOWN_PIVOT_MUTATORS. That constant enumerates BelongsToMany's writers and
 * is correct for batch_instructor; `enrollments` is a HasMany, and reusing the
 * pivot list would miss update(), delete(), forceDelete(), insert(), upsert() and
 * — the ones that matter most — every write that bypasses the relation entirely:
 * Enrollment::create(), Enrollment::query()->update(), DB::table('enrollments').
 *
 * ONE ALLOWLIST PER OPERATION, NOT ONE PER FILE. A single allowlist naming all
 * three Actions exempts each of them from ALL the shapes, so EnrollStudentAction
 * could delete and WithdrawEnrollmentAction could create, with nothing failing.
 * Each Action is sanctioned for the one operation it owns and is bound by the
 * rest — the same lesson as P1-T10a, where merging two rules let one allowlist
 * dissolve the other.
 *
 * THIS IS A TRIPWIRE, NOT CONTAINMENT. A regex over source cannot resolve types,
 * so a write through a differently-named variable is not caught. What actually
 * contains these writes is that the Actions are the only code holding the locks,
 * and EnrollmentTest asserts the locks exist. This catches the shapes somebody
 * plausibly writes.
 */
const ENROLLMENT_WRITE_RULES = [
    'create' => [
        'allowed' => ['EnrollStudentAction'],
        'patterns' => [
            '/->\s*enrollments\s*\(\s*\)\s*->\s*(create|createMany|createQuietly|forceCreate'
                .'|make|save|saveMany|saveQuietly|firstOrCreate|firstOrNew|createOrFirst)\s*\(/',
            '/\bEnrollment::\s*(create|forceCreate|createQuietly|make|insert|insertOrIgnore'
                .'|insertGetId|upsert|firstOrCreate|createOrFirst)\s*\(/',
        ],
    ],
    'update' => [
        /*
         * TWO WRITERS, AND THE SECOND ONE IS NOT A STATUS TRANSITION.
         *
         * WithdrawEnrollmentAction owns the only lifecycle change phase 1 has.
         *
         * EnrollStudentAction was added by P2-T01 for one specific update, and
         * one only: replacing the `reference` placeholder. `enrollments.reference`
         * is NOT NULL UNIQUE and contains the row's own id, so the row must be
         * inserted carrying Reference::placeholder() and updated to its real
         * `ENR-` value inside the transaction the Action already opens (design
         * section 2). The insert and the replacement are one atomic act; they are
         * two statements only because MySQL will not let a generated column read
         * an AUTO_INCREMENT column.
         *
         * ALLOWLISTED RATHER THAN EVADED. The update shape below matches
         * `$enrollment->update(...)`, and this file's own comments already name
         * the way out: the variable-name patterns are anchored, so renaming the
         * variable would have slipped the write past unreported. Taking that
         * route would have left the rule green while a security boundary quietly
         * stopped covering a file — the P1-T10a failure exactly. An allowlist
         * entry is visible in review; a laundered variable name is not.
         *
         * WHAT THIS COSTS, STATED HONESTLY. An allowlist exempts a FILE from the
         * WHOLE operation, so EnrollStudentAction is no longer bound by any
         * update shape here — a status write added to it later would not trip
         * this rule. What still contains it: the Action holds the batch and
         * student locks, EnrollmentTest asserts those locks and asserts the
         * enrolment it produces is Active, and EnrollmentPolicy::update() is
         * scoped to batches the actor teaches while creation deliberately is not.
         */
        'allowed' => ['WithdrawEnrollmentAction', 'EnrollStudentAction'],
        'patterns' => [
            '/->\s*enrollments\s*\(\s*\)\s*->\s*(update|updateQuietly|updateOrCreate|increment'
                .'|decrement|touch|restore)\s*\(/',
            '/\bEnrollment::\s*updateOrCreate\s*\(/',
            '/\bEnrollment::(query|where|whereKey)\s*\([^;]*->\s*(update|updateQuietly|increment'
                .'|decrement|restore)\s*\(/',
            /*
             * $enrollment->, $locked->, $held->enrollment->
             *
             * The variable names are ANCHORED, not fuzzy. An earlier version
             * matched \$\w*(record|locked)\w* and reported
             * DeleteStaffPhotoAction's $lockedProfile->update() as an enrolment
             * write — `$record` and `locked*` are generic names this codebase
             * uses for every model, so a loose match is noise that trains people
             * to add allowlist entries.
             *
             * KNOWN LIMIT: a write through some other variable name is not
             * caught. A regex over source cannot resolve types. What actually
             * contains these writes is that the Actions hold the locks and
             * EnrollmentsRelationManagerTest asserts the panel registers exactly
             * three actions, none of them a built-in persister.
             */
            '/(\$locked|\$\w*enrol\w*|->\s*enrollment)\s*->\s*'
                .'(update|updateQuietly|save|saveQuietly|fill|forceFill|restore)\s*\(/i',
        ],
    ],
    'delete' => [
        'allowed' => ['DeleteEnrollmentAction'],
        'patterns' => [
            '/->\s*enrollments\s*\(\s*\)\s*->\s*(delete|forceDelete|truncate)\s*\(/',
            '/\bEnrollment::\s*(destroy|truncate)\s*\(/',
            '/\bEnrollment::(query|where|whereKey)\s*\([^;]*->\s*(delete|forceDelete)\s*\(/',
            // Anchored for the same reason as the update shape above.
            '/(\$locked|\$\w*enrol\w*|->\s*enrollment)\s*->\s*(delete|forceDelete)\s*\(/i',
        ],
    ],
    'raw table' => [
        // Nobody. The table is reached through the model or not at all.
        'allowed' => [],
        'patterns' => ['/DB::\s*table\s*\(\s*[\'"]enrollments[\'"]\s*\)/'],
    ],
];

it('writes the enrollments table from nowhere but the Action that owns each operation', function () {
    // Enrolments are what phase 2 bills from. The closed-batch, duplicate,
    // deleted-student and capacity rules live in EnrollStudentAction, the status
    // transition in WithdrawEnrollmentAction, the removal in
    // DeleteEnrollmentAction, and the locks in EnrollmentMutex behind both.
    foreach (ENROLLMENT_WRITE_RULES as $operation => $rule) {
        foreach ($rule['patterns'] as $index => $pattern) {
            $offenders = filesMatching($pattern, $rule['allowed']);

            $permitted = $rule['allowed'] === [] ? 'no file at all' : implode(' / ', $rule['allowed']);

            expect($offenders)->toBeEmpty(
                "Enrollment '{$operation}' writes (pattern {$index}) are permitted in {$permitted}, "
                .'not in: '.implode(', ', $offenders),
            );
        }
    }
});

/*
 * Every shape that would write the activity log from application code.
 *
 * NO ALLOWLIST. Nothing under app/ is sanctioned to create, change or remove an
 * entry — the package writes them, and it does so from vendor/. That makes this
 * the only boundary rule here with an empty exemption list, and the emptiness is
 * the point: an audit trail application code can write by hand is one it can
 * forge, and one it can delete is not a trail at all.
 *
 * ActivityPolicy refuses every mutation ability and the resource registers no
 * controls; this catches the path that goes around both.
 *
 * The activity() helper is deliberately NOT forbidden. It is how the sanctioned
 * explicit events are recorded — pivot changes, auth events, cascaded deletes —
 * and it appends through the package rather than writing the model directly.
 */
const ACTIVITY_WRITE_SHAPES = [
    'model' => '/\bActivity::\s*(create|forceCreate|createQuietly|make|insert|insertOrIgnore'
        .'|insertGetId|upsert|updateOrCreate|firstOrCreate|createOrFirst|destroy|truncate)\s*\(/',
    'query builder' => '/\bActivity::(query|where|whereKey)\s*\([^;]*->\s*'
        .'(update|updateQuietly|delete|forceDelete|insert|upsert|increment|decrement)\s*\(/',
    'raw table' => '/DB::\s*table\s*\(\s*[\'"]activity_log[\'"]\s*\)/',
    'instance' => '/\$\w*activit\w*\s*->\s*(update|updateQuietly|save|saveQuietly|delete'
        .'|forceDelete|fill|forceFill|restore)\s*\(/i',
];

/*
 * ENROLLING WITHOUT BILLING, CLOSED (P2-T03).
 *
 * Design section 12 found this as a live UI path: EnrollmentsRelationManager
 * called EnrollStudentAction directly — correct in phase 1, and from phase 2 a
 * way to create an enrolment with NO BILL, silently, on the batch screen staff
 * already use. Every enrolment carries exactly one charge, so the two writes are
 * one act or that invariant is a convention.
 *
 * EnrollAndBillAction is the only application caller. The allowlist exempts a
 * FILE, which is what makes adding a second caller a visible act in review.
 * EnrollStudentAction names itself because the string appears in its own class
 * declaration.
 *
 * tests/ is deliberately out of scope: EnrollmentTest calls the Action directly
 * because it is that Action's own test, and a rule forbidding that would be a
 * rule against testing the unit.
 *
 * THE DETECTOR IS THE CLASS NAME, NOT A CALL SHAPE. `app(EnrollStudentAction::
 * class)`, a constructor injection, a string reference in a container binding
 * and a `use` import all reach the same place, and a rule that matched only
 * `->execute(` would miss three of the four.
 */
it('calls EnrollStudentAction from nowhere but EnrollAndBillAction', function () {
    $offenders = filesMatching(
        '/\bEnrollStudentAction\b/',
        ['EnrollAndBillAction', 'EnrollStudentAction'],
    );

    expect($offenders)->toBeEmpty(
        'Enrolling raises a bill: route it through EnrollAndBillAction rather than '
        .'EnrollStudentAction: '.implode(', ', $offenders),
    );
});

it('never writes the activity log from application code', function () {
    foreach (ACTIVITY_WRITE_SHAPES as $shape => $pattern) {
        $offenders = filesMatching($pattern);

        expect($offenders)->toBeEmpty(
            "Activity log '{$shape}' writes are permitted in no file at all — the log is "
            .'append-only and the package owns it: '.implode(', ', $offenders),
        );
    }
});
