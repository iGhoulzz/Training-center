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
| The two test names are deliberately distinct: developers use
| training_center_test, while CI provisions training_center_ci in its isolated
| per-run MySQL service container.
*/
return [
    'allowed_databases' => [
        'training_center_test',
        'training_center_ci',
        'training_center_performance',
    ],
];
