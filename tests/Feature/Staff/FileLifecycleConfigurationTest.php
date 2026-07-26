<?php

declare(strict_types=1);

use App\Domain\Staff\Services\FileLifecycleService;

it('keeps an explicitly dedicated database queue connection', function () {
    $ownerConnection = (string) config('database.default');
    $ownerConfiguration = config('database.connections.'.$ownerConnection);
    $databaseQueue = config('queue.connections.database');

    assert(is_array($ownerConfiguration));
    assert(is_array($databaseQueue));

    config([
        'database.connections.task_6b_dedicated_queue' => $ownerConfiguration,
        'queue.connections.task_6b_dedicated_queue' => [
            ...$databaseQueue,
            'connection' => 'task_6b_dedicated_queue',
        ],
        'queue.default' => 'task_6b_dedicated_queue',
    ]);

    /*
     * Workers for this queue poll task_6b_dedicated_queue. Redirecting the job
     * to the application's compensation connection would make it durable but
     * permanently invisible to those workers.
     */
    expect(FileLifecycleService::compensationQueueConnectionName())
        ->toBe('task_6b_dedicated_queue')
        ->and(config('queue.connections.task_6b_dedicated_queue.connection'))
        ->toBe('task_6b_dedicated_queue');
});
