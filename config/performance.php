<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Disposable performance databases
|--------------------------------------------------------------------------
|
| These are the only databases the destructive performance tooling may ever
| rebuild. The names are literals on purpose: an environment variable that can
| add an arbitrary database would turn the allowlist into another spelling of
| the command-line target rather than an independently reviewed boundary.
| The three test names are deliberately distinct, one per environment that runs
| the suite: developers on Windows use training_center_test; CI provisions
| training_center_ci in its isolated per-run MySQL service container; and the
| Linux development target (P35-T14, docker/dev-linux) uses
| training_center_linux on its own compose-project MySQL server. None of them
| may share a name, because the suite lock does not cross those boundaries.
*/
return [
    'allowed_databases' => [
        'training_center_test',
        'training_center_ci',
        'training_center_linux',
        'training_center_performance',
    ],

    /*
    |--------------------------------------------------------------------------
    | Per-worktree test databases
    |--------------------------------------------------------------------------
    |
    | Every checkout now runs against a database whose name is GENERATED, so that
    | two worktrees' suites no longer queue behind one schema — see
    | Tooling\TestDatabase and tests/bootstrap.php. Generated names cannot be
    | listed literally, which is why this one exception to the rule above exists.
    |
    | IT IS AS NARROW AS THE GENERATOR: the shared test name, one underscore, and
    | exactly eight lowercase hex characters. It cannot match `training_center`,
    | any production-shaped name, or a name a person would pick by hand. Widening
    | it is the same decision as adding a literal above, and wants the same review.
    |
    | The anchor is \z rather than $, because $ also matches before a trailing
    | newline, which would admit "training_center_test_deadbeef\n".
    |
    | Null disables the exception, which is how a test can prove the literal
    | allowlist still refuses on its own.
    */
    'allowed_database_pattern' => '/^training_center_test_[0-9a-f]{8}\z/',
];
