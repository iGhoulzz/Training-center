<?php

declare(strict_types=1);

/*
 * The fast gate: formatting and static analysis, no test suite.
 *
 * `composer verify:fast` is an alias for this file rather than the other way
 * round, so that hooks can invoke it without depending on a `composer`
 * executable being resolvable — on Windows that is a `.bat` shim, which
 * CreateProcess will not find without a shell, and the hooks here run under
 * Git Bash, cmd.exe and PowerShell depending on the agent.
 */

use Tooling\Gate;
use Tooling\Repo;

require __DIR__.'/../../vendor/autoload.php';

chdir(Repo::root());

exit(Gate::runFast());
