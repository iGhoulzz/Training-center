<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

/**
 * How the parent of a `--parallel` run builds its application: after the bootstrap.
 *
 * Laravel's ParallelRunner looks for exactly this trait, and only it uses it
 * (RunsInParallel::createApplication()). The parent builds one application per
 * worker and runs the setup-process callbacks on each one, and with
 * `--recreate-databases` one of those callbacks drops `{DB_DATABASE}_test_{N}`.
 * ParaTest loads PHPUnit's bootstrap later, after those callbacks have run. So
 * without this trait, the run's lock was taken after the drop. A second run of
 * the same checkout could then drop a worker database while the first run's
 * worker was still using it.
 *
 * Requiring the bootstrap here makes the parent resolve and lock the run's
 * database before Laravel exists. The bootstrap runs only once per process, so
 * when ParaTest loads it again later, nothing happens.
 *
 * Tests\TestCase does not use this trait. A test's application is built by the
 * framework's TestCase, in a process whose bootstrap has already run.
 */
trait CreatesApplication
{
    public function createApplication(): Application
    {
        require_once __DIR__.'/bootstrap.php';

        $app = require Application::inferBasePath().'/bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }
}
