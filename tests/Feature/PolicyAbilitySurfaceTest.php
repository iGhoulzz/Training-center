<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\File;

/*
|--------------------------------------------------------------------------
| Every policy must state an answer for every Filament ability (P1-T15)
|--------------------------------------------------------------------------
|
| THE DEFECT THIS EXISTS TO PREVENT.
|
| Laravel and Filament disagree about a MISSING policy method, and they disagree
| in opposite directions:
|
|   Laravel   Gate::allows('restore', $record)  -> FALSE
|             "if (! is_callable([$policy, $method])) { return false; }"
|             vendor/laravel/framework/src/Illuminate/Auth/Access/Gate.php
|
|   Filament  UserResource::canRestore($record) -> TRUE
|             get_authorization_response() consults the Gate only when
|             method_exists($policy, $action). Otherwise, with strict
|             authorization off (the default; this panel never enables it) and
|             no Gate::before callback registered, it returns Response::allow().
|             vendor/filament/filament/src/helpers.php
|
| So an ability a policy simply does not mention is DENIED everywhere a test
| would look and ALLOWED everywhere a user would click. The group 1 security
| review of phase 1 found seven policies in that state across five
| soft-deletable models, and two existing tests actively asserting it was safe
| — "there is no *Any method for Filament to authorize against, so it fails
| closed", which is true of the Gate and false of the panel.
|
| Nothing was reachable at the time: no TrashedFilter, RestoreAction,
| ForceDeleteAction or bulk action was rendered anywhere. That is what made it a
| loaded trap rather than an open door, and it is exactly why a test is the fix.
| The person who springs it is whoever next adds the standard Filament
| soft-delete idiom to a resource, and they will have no reason to suspect that
| adding a control silently grants the ability behind it.
|
| Writing the method out — even to return false — is what makes the answer real.
*/

/**
 * The abilities Filament resolves against a policy for a resource.
 *
 * Sourced from Filament's own resource authorization surface
 * (vendor/filament/filament/src/Resources/Resource/Concerns/HasAuthorization.php):
 * every can*() helper there maps to a policy method of the same name.
 *
 * @var list<string>
 */
const FILAMENT_POLICY_ABILITIES = [
    'viewAny',
    'view',
    'create',
    'update',
    'delete',
    'deleteAny',
    'restore',
    'restoreAny',
    'forceDelete',
    'forceDeleteAny',
    'replicate',
    'reorder',
];

/** @return array<int, class-string> Every policy class under app/. */
function policyClasses(): array
{
    $classes = [];

    foreach (File::allFiles(app_path()) as $file) {
        if (! str_ends_with($file->getFilename(), 'Policy.php')) {
            continue;
        }

        $relative = str_replace(
            [app_path().DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR, '.php'],
            ['', '\\', ''],
            (string) $file->getRealPath(),
        );

        $class = 'App\\'.$relative;

        if (class_exists($class)) {
            $classes[] = $class;
        }
    }

    sort($classes);

    return $classes;
}

it('finds the policies to check', function () {
    // A scan that silently matches nothing passes every assertion built on it.
    expect(count(policyClasses()))->toBeGreaterThanOrEqual(9);
});

it('states an answer for every Filament ability', function () {
    $gaps = [];

    foreach (policyClasses() as $policy) {
        $missing = array_values(array_filter(
            FILAMENT_POLICY_ABILITIES,
            fn (string $ability): bool => ! method_exists($policy, $ability),
        ));

        if ($missing !== []) {
            $gaps[] = class_basename($policy).' → '.implode(', ', $missing);
        }
    }

    expect($gaps)->toBeEmpty(
        "These policies leave abilities unstated. Filament reads an unstated ability as ALLOW,\n"
        ."so each one below is an open door waiting for someone to render its control:\n  "
        .implode("\n  ", $gaps)
        ."\nDefine the method. Returning false is a complete answer; saying nothing is not."
    );
});

it('refuses the dormant abilities rather than merely defining them', function () {
    // Defining a method that returns true would satisfy the test above while
    // reintroducing the hole. Phase 1 ships none of these operations, so every
    // policy must answer no — for every actor, rank included.
    $dormant = ['deleteAny', 'restoreAny', 'forceDeleteAny', 'reorder'];

    $permissive = [];

    foreach (policyClasses() as $policy) {
        $instance = app($policy);

        foreach ($dormant as $ability) {
            if (! method_exists($instance, $ability)) {
                continue;
            }

            $reflection = new ReflectionMethod($instance, $ability);

            // Only the no-record abilities are checked here: they take an actor
            // alone, so they can be called without constructing a subject.
            if ($reflection->getNumberOfParameters() !== 1) {
                continue;
            }

            if ($instance->{$ability}(new User) !== false) {
                $permissive[] = class_basename($policy).'::'.$ability.'()';
            }
        }
    }

    expect($permissive)->toBeEmpty(
        'These bulk abilities do not refuse. Filament authorizes a bulk action ONCE against '
        .'the *Any method and never consults the per-record rule, so any per-record protection '
        ."is skipped entirely:\n  ".implode("\n  ", $permissive),
    );
});

/*
|--------------------------------------------------------------------------
| And no comment may claim otherwise (P1-T15, domain-integrity finding 6)
|--------------------------------------------------------------------------
|
| The group 1 fix above added deleteAny() to six policies and left every file
| header still saying the method did not exist. Four of them went further and
| taught the reasoning this test disproves — "leaving it undefined makes any bulk
| delete added later fail closed" — sitting eighty lines above the block that
| corrects exactly that belief.
|
| That is worse than an ordinary stale comment. The next person to read the top
| of a policy before adding a bulk action learns the wrong lesson from the file
| that was just corrected to teach the right one, and the lesson they learn is
| the one that opens the door.
|
| The test above makes the claim checkable rather than a matter of discipline:
| every policy now states an answer for every ability, so ANY comment asserting
| that some policy lacks one is false by construction.
*/

/**
 * Does this comment claim a policy leaves a BULK or SOFT-DELETE ability
 * undefined?
 *
 * NAMED FOR WHAT IT COVERS, WHICH IS NOT EVERY POLICY ABILITY. The list below is
 * deliberately narrower than FILAMENT_POLICY_ABILITIES: bare `view()`,
 * `create()`, `update()` and `delete()` are ordinary English in this codebase's
 * prose ("there is no delete() path for the activity log"), and including them
 * would fire on sentences making no claim about the policy surface at all.
 *
 * So a comment asserting "StudentPolicy defines no delete()" would NOT be
 * caught, and that gap is stated here rather than left to be discovered — the
 * families that actually drifted, and the ones a reader is most likely to reason
 * wrongly about, are the bulk and soft-delete abilities.
 *
 * A named function with its own sample sets below rather than an inline regex,
 * because an inline pattern can only ever be tested against the codebase as it
 * happens to be today — precisely the case where it passes vacuously.
 */
function claimsABulkOrSoftDeleteAbilityIsUndefined(string $comments): bool
{
    $abilities = '(?:deleteAny|restoreAny|forceDeleteAny|restore|forceDelete|replicate|reorder)';

    $patterns = [
        // "defines no deleteAny()", "There is no deleteAny():", "has no restore()"
        '/\bno\s+'.$abilities.'\s*\(\)/i',
        // The disproved reasoning itself, which is harmful even without naming a
        // method: it is the sentence that teaches a missing method fails closed.
        '/leaving it undefined/i',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $comments) === 1) {
            return true;
        }
    }

    return false;
}

it('detects the claims it is meant to detect', function (string $sample) {
    // Deleting a rule from the detector fails here.
    expect(claimsABulkOrSoftDeleteAbilityIsUndefined($sample))->toBeTrue();
})->with([
    'EnrollmentPolicy defines no deleteAny().',
    'There is no deleteAny(): BatchResource registers no bulk actions.',
    'BatchPolicy defines no deleteAny(); nothing here would consult it anyway.',
    'StudentPolicy has no forceDelete() and never will.',
    'so leaving it undefined makes any bulk delete added later fail closed',
    'There is no restoreAny().',
]);

it('leaves correct statements about those abilities alone', function (string $sample) {
    // Over-broadening the detector fails here. Every one of these is a true
    // sentence that some file in app/ needs to be able to say.
    expect(claimsABulkOrSoftDeleteAbilityIsUndefined($sample))->toBeFalse();
})->with([
    'StaffCertificatePolicy::deleteAny() gates nothing here because there is no bulk delete to authorize.',
    'RolePolicy::deleteAny() already refuses, so the inherited action would fail anyway.',
    'No bulk actions are registered on this resource.',
    'deleteAny() returns false because bulk authorization skips the per-record rule.',
    'Filament authorizes a bulk action once against the *Any policy method.',
    'There is no delete() path for the activity log, by design.',
]);

it('has no comment claiming a policy leaves a bulk or soft-delete ability undefined', function () {
    $offenders = [];

    foreach (File::allFiles(app_path()) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $path = (string) $file->getRealPath();

        if (claimsABulkOrSoftDeleteAbilityIsUndefined(appCommentsOnly($path))) {
            $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
        }
    }

    expect($offenders)->toBeEmpty(
        'Every policy states an answer for every ability, so these comments are false — and '
        ."the ones about bulk deletion teach the reasoning this file exists to disprove:\n  "
        .implode("\n  ", $offenders),
    );
});
