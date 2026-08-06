<?php

declare(strict_types=1);

/*
 * Launch Boost's MCP server from any working directory.
 *
 * WHY THIS FILE EXISTS AT ALL.
 *
 * `.mcp.json` cannot name the project root. Claude Code sets
 * CLAUDE_PROJECT_DIR in the SPAWNED SERVER's environment, not in its own, so at
 * config-parse time the variable is unset: a bare `${CLAUDE_PROJECT_DIR}` fails
 * to expand and no server is offered at all. Giving it the documented
 * `${CLAUDE_PROJECT_DIR:-.}` default makes the config parse, but `.` is the
 * SESSION's working directory, not the project root — measured against Claude
 * Code 2.1.177, that connects from the repository root and fails from
 * `app/Domain`. The project root is only knowable inside the server process.
 *
 * So the config runs a one-line `php -r` that reads CLAUDE_PROJECT_DIR from its
 * own environment and requires this file. From here __DIR__ is known, and the
 * root follows from it with no reliance on the environment or the caller's
 * working directory.
 *
 * ARTISAN IS DELIBERATELY NOT REQUIRED. It opens with `#!/usr/bin/env php`
 * outside PHP tags, so requiring it prints that line to stdout — which is the
 * JSON-RPC channel. The client would see a malformed first frame and drop the
 * connection. The four lines below are artisan's body, minus the shebang.
 */

use Illuminate\Foundation\Application;
use Symfony\Component\Console\Input\ArgvInput;

$root = dirname(__DIR__, 2);

// Laravel resolves base_path() and every relative config path from the working
// directory of the process, so this has to be set before the app boots.
chdir($root);

define('LARAVEL_START', microtime(true));

require $root.'/vendor/autoload.php';

/** @var Application $app */
$app = require_once $root.'/bootstrap/app.php';

// The console kernel reads $_SERVER['argv'] through ArgvInput. This process was
// started as `php -r`, so it carries no artisan arguments of its own.
$_SERVER['argv'] = ['artisan', 'boost:mcp'];
$_SERVER['argc'] = 2;

exit($app->handleCommand(new ArgvInput));
