<?php

declare(strict_types=1);

use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tooling\Repo;
use Tooling\SerialLock;
use Tooling\TestDatabase;

/*
|--------------------------------------------------------------------------
| What tests/bootstrap.php does when the run is parallel
|--------------------------------------------------------------------------
|
| Paratest boots the bootstrap in the parent and again in every worker. The parent
| locks the run's database, like any run; a worker must lock a database of its own,
| so workers never wait on each other, and must still refuse every configuration the
| serial run refuses.
|
| None of that can be observed from inside a test: by the time one runs, the
| bootstrap has decided. So each case starts a real child that requires the real
| bootstrap from a script NAMED like a worker entry point — the bootstrap tells a
| worker from a child of a worker by the script that was started — reports what it
| ended up holding, and waits on stdin so the lock stays held while a sibling is
| started.
|
| Every child names a database of its own (a deliberate selection, which the
| bootstrap leaves alone), so it can never touch this suite's database or its lock.
| The expectations are written out as names and compared with the lock path those
| names give, never read back from the child.
*/

/**
 * @return array{processes: list<Process>, inputs: list<InputStream>, databases: list<string>, directories: list<string>}
 */
function &parallelProbeState(): array
{
    static $state = ['processes' => [], 'inputs' => [], 'databases' => [], 'directories' => []];

    return $state;
}

/** A database name no other test or checkout will pick, inside the shipped create-database grant. */
function parallelProbeDatabase(): string
{
    $database = 'training_center_test_probe_'.bin2hex(random_bytes(6));

    $state = &parallelProbeState();
    $state['databases'][] = $database;

    return $database;
}

/**
 * Start a child that requires the real bootstrap from a script called $script.
 *
 * @param  array<string, string|false>  $environment  false removes the variable
 * @param  list<string>  $arguments  what follows the script on the command line
 * @return array{process: Process, input: InputStream}
 */
function startBootstrapProbe(string $script, array $environment, array $arguments = []): array
{
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'parallel-bootstrap-probe-'.bin2hex(random_bytes(6));
    mkdir($directory);

    $path = $directory.DIRECTORY_SEPARATOR.$script;
    file_put_contents($path, <<<'PHP'
        <?php

        require getenv('PROBE_BOOTSTRAP');

        fwrite(STDOUT, json_encode([
            'database' => getenv('DB_DATABASE'),
            'held' => Tooling\SerialLock::heldPath(),
            'withoutDatabases' => getenv('LARAVEL_PARALLEL_TESTING_WITHOUT_DATABASES'),
        ])."\n");
        fflush(STDOUT);

        // Hold everything until the parent closes stdin or dies.
        fgets(STDIN);
        PHP);

    $input = new InputStream;
    // The caller's values come first: with `+` the left side wins, and a test
    // that sets PARATEST must beat the default that removes it.
    $process = new Process([PHP_BINARY, $path, ...$arguments], Repo::root(), $environment + [
        'PROBE_BOOTSTRAP' => Repo::root().'/tests/bootstrap.php',
        // Never inherit the worker state of the suite running this test.
        'LARAVEL_PARALLEL_TESTING_WITHOUT_DATABASES' => false,
        'PARATEST' => false,
        'TEST_TOKEN' => false,
        'UNIQUE_TEST_TOKEN' => false,
        'APP_CONFIG_CACHE' => false,
        'DB_URL' => false,
    ]);
    $process->setInput($input);
    $process->setTimeout(120);
    $process->start();

    $state = &parallelProbeState();
    $state['processes'][] = $process;
    $state['inputs'][] = $input;
    $state['directories'][] = $directory;

    return ['process' => $process, 'input' => $input];
}

/**
 * Wait until the child reports, or exits, or the deadline passes.
 *
 * @return array{database: string|false, held: string|null, withoutDatabases: string|false}|null null when it did not report
 */
function awaitBootstrapReport(Process $process, float $seconds = 40.0): ?array
{
    $deadline = microtime(true) + $seconds;

    while (microtime(true) < $deadline) {
        $line = strtok($process->getOutput(), "\n");

        if (is_string($line) && $line !== '') {
            return json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        }

        if (! $process->isRunning()) {
            return null;
        }

        usleep(50_000);
    }

    return null;
}

/** The lock file a database's holder takes, spelled from the database name alone. */
function parallelProbeLock(string $database): string
{
    return SerialLock::pathFor(Repo::lockKeyForDatabase($database));
}

afterEach(function () {
    $state = &parallelProbeState();

    foreach ($state['inputs'] as $input) {
        $input->close();
    }

    foreach ($state['processes'] as $process) {
        if ($process->isRunning()) {
            $process->stop(2);
        }
    }

    foreach ($state['directories'] as $directory) {
        foreach (glob($directory.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($directory);
    }

    if ($state['databases'] !== []) {
        $connection = TestDatabase::connectionFromEnvironment(Repo::root());
        $pdo = new PDO("mysql:host={$connection['host']};port={$connection['port']}", $connection['username'], $connection['password']);

        foreach ($state['databases'] as $base) {
            foreach ([$base, ...array_map(fn (int $token): string => "{$base}_test_{$token}", range(1, 9))] as $database) {
                TestDatabase::assertSafeIdentifier($database);
                $pdo->exec("DROP DATABASE IF EXISTS `{$database}`");
            }
        }
    }

    $state = ['processes' => [], 'inputs' => [], 'databases' => [], 'directories' => []];
});

it('gives a worker a database of its own and locks that one, not the run database', function () {
    $base = parallelProbeDatabase();

    ['process' => $worker] = startBootstrapProbe('worker.php', [
        'DB_DATABASE' => $base,
        'PARATEST' => '1',
        'TEST_TOKEN' => '7',
    ]);

    $report = awaitBootstrapReport($worker);

    expect($report)->not->toBeNull($worker->getErrorOutput())
        ->and($report['database'])->toBe("{$base}_test_7")
        ->and($report['held'])->toBe(parallelProbeLock("{$base}_test_7"))
        ->and($report['held'])->not->toBe(parallelProbeLock($base))
        // Laravel's own switch is off, or it would derive a second database.
        ->and($report['withoutDatabases'])->toBe('1');
});

it('creates the worker database it is going to use', function () {
    $base = parallelProbeDatabase();

    ['process' => $worker] = startBootstrapProbe('worker.php', [
        'DB_DATABASE' => $base,
        'PARATEST' => '1',
        'TEST_TOKEN' => '3',
    ]);

    awaitBootstrapReport($worker);

    $connection = TestDatabase::connectionFromEnvironment(Repo::root());
    $pdo = new PDO("mysql:host={$connection['host']};port={$connection['port']}", $connection['username'], $connection['password']);
    $found = $pdo->query("SHOW DATABASES LIKE '{$base}\\_test\\_3'")->fetchAll();

    expect($found)->toHaveCount(1);
});

it('does not refuse --parallel, and locks the run database in the parent process', function () {
    /*
     * The parent of a parallel run boots the bootstrap too, with `--parallel` on its
     * command line and no worker variables. It used to exit here. It must now behave
     * as the run's coordinator: take the lock on the run's database, which is what
     * keeps a second run of the same checkout waiting before any worker exists.
     */
    $base = parallelProbeDatabase();

    ['process' => $parent] = startBootstrapProbe('pest', ['DB_DATABASE' => $base], ['--parallel']);

    $report = awaitBootstrapReport($parent);

    expect($report)->not->toBeNull($parent->getErrorOutput())
        ->and($parent->getErrorOutput())->not->toContain('not supported')
        ->and($report['database'])->toBe($base)
        ->and($report['held'])->toBe(parallelProbeLock($base));
});

it('keeps the run database for a run that is not itself a worker', function (array $inherited) {
    /*
     * THE TWO SHAPES THAT MUST NOT BE MISTAKEN FOR A WORKER.
     *
     * A serial run has no worker variables at all. A child a test spawns — a
     * nested `pest`, a phpunit probe — has them, because the environment is
     * inherited, but its entry script is not a worker's. Judged by the variables
     * alone, the child would lock a worker database derived from its parent's.
     */
    $base = parallelProbeDatabase();

    ['process' => $run] = startBootstrapProbe('probe.php', $inherited + ['DB_DATABASE' => $base]);

    $report = awaitBootstrapReport($run);

    expect($report)->not->toBeNull($run->getErrorOutput())
        ->and($report['database'])->toBe($base)
        ->and($report['held'])->toBe(parallelProbeLock($base))
        ->and($report['withoutDatabases'])->toBeFalse();
})->with([
    'a serial run' => [[]],
    'a child of a worker' => [['PARATEST' => '1', 'TEST_TOKEN' => '4']],
]);

it('lets two workers of one run hold their locks at the same time', function () {
    $base = parallelProbeDatabase();

    ['process' => $first] = startBootstrapProbe('worker.php', ['DB_DATABASE' => $base, 'PARATEST' => '1', 'TEST_TOKEN' => '1']);
    $firstReport = awaitBootstrapReport($first);

    ['process' => $second] = startBootstrapProbe('worker.php', ['DB_DATABASE' => $base, 'PARATEST' => '1', 'TEST_TOKEN' => '2']);
    $secondReport = awaitBootstrapReport($second);

    // The first is still holding its lock when the second reports: it never waited.
    expect($first->isRunning())->toBeTrue()
        ->and($firstReport['held'])->toBe(parallelProbeLock("{$base}_test_1"))
        ->and($secondReport['held'])->toBe(parallelProbeLock("{$base}_test_2"))
        ->and($second->getErrorOutput())->not->toContain('Waiting for the shared test database');
});

it('makes two processes that reach the same worker database take turns', function () {
    $base = parallelProbeDatabase();

    ['process' => $holder, 'input' => $holderInput] = startBootstrapProbe('worker.php', ['DB_DATABASE' => $base, 'PARATEST' => '1', 'TEST_TOKEN' => '5']);
    awaitBootstrapReport($holder);

    ['process' => $waiter] = startBootstrapProbe('worker.php', ['DB_DATABASE' => $base, 'PARATEST' => '1', 'TEST_TOKEN' => '5']);

    $deadline = microtime(true) + 30;

    while (microtime(true) < $deadline && ! str_contains($waiter->getErrorOutput(), 'Waiting for the shared test database')) {
        usleep(50_000);
    }

    expect($waiter->getErrorOutput())->toContain('Waiting for the shared test database')
        // Blocked, so it has not reported a held lock.
        ->and($waiter->getOutput())->toBe('');

    // Closing the stream only queues the end of input; the process must be pumped
    // for the child to see it. Waiting for the holder to exit releases its lock.
    $holderInput->close();
    $holder->wait();

    $report = awaitBootstrapReport($waiter);

    expect($report)->not->toBeNull($waiter->getErrorOutput())
        ->and($report['held'])->toBe(parallelProbeLock("{$base}_test_5"));
});

it('refuses a worker that has no token', function () {
    $base = parallelProbeDatabase();

    ['process' => $worker] = startBootstrapProbe('worker.php', ['DB_DATABASE' => $base, 'PARATEST' => '1']);
    $worker->wait();

    expect($worker->getExitCode())->not->toBe(0)
        ->and($worker->getErrorOutput())->toContain('has no TEST_TOKEN');
});

it('still refuses every unsafe configuration when the process is a worker', function (array $environment, string $message) {
    $worker = startBootstrapProbe('worker.php', $environment + [
        'DB_DATABASE' => parallelProbeDatabase(),
        'PARATEST' => '1',
        'TEST_TOKEN' => '6',
    ])['process'];
    $worker->wait();

    expect($worker->getExitCode())->not->toBe(0)
        ->and($worker->getErrorOutput())->toContain($message);
})->with([
    'DB_URL outranking the database' => [['DB_URL' => 'mysql://root@127.0.0.1:3306/some_other_database'], 'DB_URL is set'],
    'no database selected' => [['DB_DATABASE' => false], 'DB_DATABASE is not set'],
]);

it('still refuses a cached configuration when the process is a worker', function () {
    $relative = 'storage/framework/testing/config-cache-'.bin2hex(random_bytes(6)).'.php';
    $cache = Repo::root().'/'.$relative;

    if (! is_dir(dirname($cache))) {
        mkdir(dirname($cache), recursive: true);
    }

    file_put_contents($cache, '<?php return [];');

    try {
        $worker = startBootstrapProbe('worker.php', [
            'DB_DATABASE' => parallelProbeDatabase(),
            'PARATEST' => '1',
            'TEST_TOKEN' => '6',
            'APP_CONFIG_CACHE' => $relative,
        ])['process'];
        $worker->wait();

        expect($worker->getExitCode())->not->toBe(0)
            ->and($worker->getErrorOutput())->toContain('A cached configuration is present');
    } finally {
        @unlink($cache);
    }
});

it('names the database of a worker the way Laravel names it', function () {
    expect(TestDatabase::workerDatabase('training_center_ci', '3'))->toBe('training_center_ci_test_3')
        ->and(TestDatabase::workerDatabase('training_center_test_c87badbb', '16'))->toBe('training_center_test_c87badbb_test_16');
});

it('refuses a token it could not have been given', function (string $token) {
    expect(fn () => TestDatabase::workerDatabase('training_center_ci', $token))
        ->toThrow(RuntimeException::class);
})->with(['empty' => [''], 'letters' => ['abc'], 'negative' => ['-1'], 'sql' => ['1`; DROP DATABASE x; --'], 'newline' => ["1\n"], 'too long' => ['1234567']]);

it('recognises only the worker entry points as workers', function (string $script, bool $expected) {
    expect(TestDatabase::isWorkerEntryPoint($script))->toBe($expected);
})->with([
    'pest worker' => ['C:\\repo\\vendor\\pestphp\\pest\\bin\\worker.php', true],
    'paratest worker' => ['/repo/vendor/brianium/paratest/bin/phpunit-worker.php', true],
    'pest itself' => ['/repo/vendor/bin/pest', false],
    'artisan' => ['artisan', false],
    'a script that merely ends the same way' => ['/repo/not-worker.php', false],
    'nothing' => ['', false],
]);
