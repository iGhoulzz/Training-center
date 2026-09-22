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
];
