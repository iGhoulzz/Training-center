<?php

declare(strict_types=1);

/*
 * The one supported manual Boost update entry point.
 *
 * Boost owns only its marked block. Its native writer also normalizes blank
 * lines across the whole file, so this runner restores every project-owned byte
 * around that block after the native update. Project rules remain disabled for
 * this process while guidelines, skills and package discovery remain available.
 */

use RuntimeException;
use Throwable;
use Tooling\BoostGuidelines;
use Tooling\Process;
use Tooling\Repo;

require __DIR__.'/../../vendor/autoload.php';

$root = Repo::root();
$files = ['CLAUDE.md', 'AGENTS.md'];
$originals = [];

foreach ($files as $file) {
    $contents = file_get_contents($root.'/'.$file);

    if ($contents === false) {
        fwrite(STDERR, "Unable to read {$file}.\n");

        exit(1);
    }

    BoostGuidelines::split($contents, $file);
    $originals[$file] = $contents;
}

putenv('BOOST_RULES_ENABLED=false');
$_ENV['BOOST_RULES_ENABLED'] = 'false';
$_SERVER['BOOST_RULES_ENABLED'] = 'false';

$status = Process::stream(
    [PHP_BINARY, 'artisan', 'boost:update', '--no-discover'],
    $root,
);

if ($status !== 0) {
    foreach ($originals as $file => $contents) {
        file_put_contents($root.'/'.$file, $contents);
    }

    exit($status);
}

try {
    foreach ($originals as $file => $original) {
        $path = $root.'/'.$file;
        $updated = file_get_contents($path);

        if ($updated === false) {
            throw new RuntimeException("Unable to read updated {$file}.");
        }

        $preserved = BoostGuidelines::withUpdatedBlock($original, $updated, $file);

        if (file_put_contents($path, $preserved) === false) {
            throw new RuntimeException("Unable to write updated {$file}.");
        }
    }
} catch (Throwable $exception) {
    foreach ($originals as $file => $contents) {
        file_put_contents($root.'/'.$file, $contents);
    }

    fwrite(STDERR, $exception->getMessage()."\n");

    exit(1);
}

exit(0);
