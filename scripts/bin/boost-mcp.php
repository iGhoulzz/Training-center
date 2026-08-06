<?php

declare(strict_types=1);

/*
 * Launch Boost's MCP server from any working directory.
 *
 * WHY THIS FILE EXISTS AT ALL.
 *
 * `.mcp.json` cannot name the project root, and three attempts to make it try
 * all failed against Claude Code 2.1.177:
 *
 *   ${CLAUDE_PROJECT_DIR}/artisan     never expands. The variable is set in the
 *     SPAWNED SERVER's environment, not Claude Code's, so at config-parse time
 *     it is unset and no server is offered at all.
 *
 *   ${CLAUDE_PROJECT_DIR:-.}/artisan  parses, then resolves `.` against the
 *     SESSION's working directory: connected from the repository root, failed
 *     from app/Domain.
 *
 *   getenv('CLAUDE_PROJECT_DIR') ?: getcwd()   same outcome. The variable is
 *     not usable by the `php -r` snippet either, so it fell back to the nested
 *     working directory and could not find this file.
 *
 * So the config assumes NOTHING about the environment. It walks upward from the
 * working directory until it finds this launcher, which works from any
 * directory inside the project. From here __DIR__ is known and the root follows
 * from it.
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
