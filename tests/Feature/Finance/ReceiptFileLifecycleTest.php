<?php

declare(strict_types=1);

use App\Domain\Finance\Actions\AttachReceiptAction;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Support\ReceiptLocation;
use App\Domain\Staff\Jobs\PurgeDeletedFileJob;
use App\Domain\Staff\Models\PendingFileDeletion;
use App\Domain\Staff\Services\FileLifecycleService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(DatabaseTruncation::class);

afterAll(function (): void {
    RefreshDatabaseState::$migrated = false;
});

afterEach(function (): void {
    DB::disconnect(FileLifecycleService::compensationConnectionName());
});

beforeEach(function (): void {
    Storage::fake(ReceiptLocation::DISK);
});

it('purges receipt bytes when an outer owner transaction rolls back', function (): void {
    $payment = Payment::factory()->create();
    $path = app(ReceiptLocation::class)->path($payment);
    $originalQueueConnection = config('queue.default');
    $startingLevel = DB::transactionLevel();
    config(['queue.default' => 'sync']);

    try {
        DB::beginTransaction();

        app(AttachReceiptAction::class)->execute(
            (int) $payment->getKey(),
            $path,
            'receipt-created-inside-the-outer-transaction',
        );

        expect($payment->refresh()->receipt_path)->toBe($path);
        Storage::disk(ReceiptLocation::DISK)->assertExists($path);

        DB::rollBack();
    } finally {
        while (DB::transactionLevel() > $startingLevel) {
            DB::rollBack();
        }

        config(['queue.default' => $originalQueueConnection]);
    }

    expect($payment->refresh()->receipt_path)->toBeNull()
        ->and(PendingFileDeletion::on(FileLifecycleService::compensationConnectionName())->count())->toBe(0);
    Storage::disk(ReceiptLocation::DISK)->assertMissing($path);
});

it('keeps an exact canonical receipt owned after an ambiguous commit outcome', function (): void {
    $payment = Payment::factory()->create();
    $path = app(ReceiptLocation::class)->path($payment);
    $payment->update([
        'receipt_disk' => ReceiptLocation::DISK,
        'receipt_path' => $path,
    ]);
    Storage::disk(ReceiptLocation::DISK)->put($path, 'committed-receipt-bytes');

    $pending = PendingFileDeletion::on(FileLifecycleService::compensationConnectionName())->create([
        'disk' => ReceiptLocation::DISK,
        'path' => $path,
        'attempts' => 0,
        'last_error' => null,
    ]);
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        if ($query->connectionName === FileLifecycleService::compensationConnectionName()) {
            $queries[] = strtolower($query->sql);
        }
    });

    (new PurgeDeletedFileJob((int) $pending->getKey(), true))->handle();

    Storage::disk(ReceiptLocation::DISK)->assertExists($path);
    expect(PendingFileDeletion::on(FileLifecycleService::compensationConnectionName())
        ->whereKey($pending->getKey())
        ->exists())->toBeFalse()
        ->and(collect($queries)->contains(
            fn (string $sql): bool => str_contains($sql, 'payments') && str_contains($sql, 'for update'),
        ))->toBeTrue('Receipt ownership was not locked on the compensation connection.');
});

it('purges a receipt-shaped path whose payment pointer is not canonical', function (): void {
    $payment = Payment::factory()->create();
    $path = 'receipts/RCT-1999-999999.pdf';
    $payment->update([
        'receipt_disk' => ReceiptLocation::DISK,
        'receipt_path' => $path,
    ]);
    Storage::disk(ReceiptLocation::DISK)->put($path, 'unowned-receipt-bytes');

    $pending = PendingFileDeletion::on(FileLifecycleService::compensationConnectionName())->create([
        'disk' => ReceiptLocation::DISK,
        'path' => $path,
        'attempts' => 0,
        'last_error' => null,
    ]);

    (new PurgeDeletedFileJob((int) $pending->getKey(), true))->handle();

    Storage::disk(ReceiptLocation::DISK)->assertMissing($path);
    expect(PendingFileDeletion::on(FileLifecycleService::compensationConnectionName())
        ->whereKey($pending->getKey())
        ->exists())->toBeFalse();
});
