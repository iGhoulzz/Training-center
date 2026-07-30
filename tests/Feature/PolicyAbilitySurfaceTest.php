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
