<?php

declare(strict_types=1);

use App\Domain\Enrollment\Services\EnrollmentQueryService;
use App\Domain\Finance\Actions\AttachReceiptAction;
use App\Domain\Finance\Actions\RecordPaymentAction;
use App\Domain\Finance\Data\RecordPaymentData;
use App\Domain\Finance\Data\TenderData;
use App\Domain\Finance\Enums\TenderMethod;
use App\Domain\Finance\Jobs\GenerateReceiptJob;
use App\Domain\Finance\Models\Charge;
use App\Domain\Finance\Models\Discount;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Support\ChargeBalance;
use App\Domain\Finance\Support\ReceiptLocation;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Domain\Staff\Jobs\PurgeDeletedFileJob;
use App\Domain\Staff\Models\PendingFileDeletion;
use App\Domain\Staff\Services\FileLifecycleService;
use App\Models\User;
use App\Support\CentreCalendar;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\Process\Process;

uses(DatabaseTruncation::class);

afterAll(function (): void {
    RefreshDatabaseState::$migrated = false;
});

/** @return array<string, string> */
function receiptWorkerEnvironment(string $privateDiskRoot): array
{
    $connection = (string) config('database.default');
    $database = config("database.connections.{$connection}");

    return [
        'APP_ENV' => 'testing',
        'APP_KEY' => (string) config('app.key'),
        'DB_CONNECTION' => $connection,
        'DB_HOST' => (string) ($database['host'] ?? ''),
        'DB_PORT' => (string) ($database['port'] ?? ''),
        'DB_DATABASE' => (string) ($database['database'] ?? ''),
        'DB_USERNAME' => (string) ($database['username'] ?? ''),
        'DB_PASSWORD' => (string) ($database['password'] ?? ''),
        'PRIVATE_DISK_ROOT' => $privateDiskRoot,
    ];
}

function receiptAttachmentWorker(
    int $paymentId,
    string $path,
    string $bytes,
    string $readyPath,
    string $resultPath,
): Process {
    $script = <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        config(['filesystems.disks.private.root' => getenv('PRIVATE_DISK_ROOT')]);
        Illuminate\Support\Facades\Storage::forgetDisk('private');

        [$script, $paymentId, $path, $bytes, $readyPath, $resultPath] = $_SERVER['argv'];
        file_put_contents($readyPath, 'ready');
        $attached = app(App\Domain\Finance\Actions\AttachReceiptAction::class)->execute(
            (int) $paymentId,
            $path,
            $bytes,
        );
        file_put_contents($resultPath, json_encode(['attached' => $attached], JSON_THROW_ON_ERROR));
        PHP;

    return new Process(
        [PHP_BINARY, '-r', $script, (string) $paymentId, $path, $bytes, $readyPath, $resultPath],
        base_path(),
        receiptWorkerEnvironment(Storage::disk('private')->path('')),
    );
}

function failingReceiptAttachmentWorker(
    int $paymentId,
    string $path,
    string $bytes,
    string $readyPath,
    string $activityPath,
    string $releasePath,
    string $successResultPath,
    string $resultPath,
): Process {
    $script = <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        config(['filesystems.disks.private.root' => getenv('PRIVATE_DISK_ROOT')]);
        Illuminate\Support\Facades\Storage::forgetDisk('private');

        [
            $script,
            $paymentId,
            $path,
            $bytes,
            $readyPath,
            $activityPath,
            $releasePath,
            $successResultPath,
            $resultPath,
        ] = $_SERVER['argv'];

        Illuminate\Support\Facades\Event::listen(
            Illuminate\Database\Events\TransactionRolledBack::class,
            static function () use ($successResultPath): void {
                $deadline = microtime(true) + 10;

                while (! file_exists($successResultPath) && microtime(true) < $deadline) {
                    usleep(25_000);
                }

                if (! file_exists($successResultPath)) {
                    throw new RuntimeException('The successor did not finish after rollback.');
                }
            },
        );

        Illuminate\Support\Facades\DB::listen(static function ($query) use ($activityPath, $releasePath): void {
            if (! str_contains($query->sql, 'activity_log')) {
                return;
            }

            file_put_contents($activityPath, 'activity-inserted');
            $deadline = microtime(true) + 10;

            while (! file_exists($releasePath) && microtime(true) < $deadline) {
                usleep(25_000);
            }

            if (! file_exists($releasePath)) {
                throw new RuntimeException('The failing worker was not released.');
            }

            throw new RuntimeException('forced concurrent activity failure');
        });

        file_put_contents($readyPath, 'ready');

        try {
            $attached = app(App\Domain\Finance\Actions\AttachReceiptAction::class)->execute(
                (int) $paymentId,
                $path,
                $bytes,
            );
            $result = ['outcome' => 'attached', 'attached' => $attached];
        } catch (Throwable $throwable) {
            $result = ['outcome' => 'failed', 'message' => $throwable->getMessage()];
        }

        file_put_contents($resultPath, json_encode($result, JSON_THROW_ON_ERROR));
        PHP;

    return new Process(
        [
            PHP_BINARY,
            '-r',
            $script,
            (string) $paymentId,
            $path,
            $bytes,
            $readyPath,
            $activityPath,
            $releasePath,
            $successResultPath,
            $resultPath,
        ],
        base_path(),
        receiptWorkerEnvironment(Storage::disk('private')->path('')),
    );
}

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    Storage::fake('private');
    config(['queue.default' => 'database']);

    $admin = User::factory()->create(['is_active' => true]);
    app(SystemRoleWriter::class)->assignRoles($admin, 'admin');

    $this->admin = $admin->refresh();
    $this->recordPayment = app(RecordPaymentAction::class);
    $this->paymentData = fn (int $chargeId, string $key, string $allocation = '1000.000', ?array $tenders = null): RecordPaymentData => new RecordPaymentData(
        chargeId: $chargeId,
        allocation: $allocation,
        tenders: $tenders ?? [
            new TenderData(TenderMethod::Card, '300.000', 'AUTH-1234567890'),
            new TenderData(TenderMethod::Cash, '700.000'),
        ],
        idempotencyKey: $key,
    );
});

it('creates one immutable receipt snapshot inside the payment transaction', function (): void {
    Queue::fake();
    app()->setLocale('en');
    $charge = Charge::factory()->create([
        'list_price' => '1250.000',
        'discount_percentage' => null,
        'amount' => '1250.000',
    ]);
    $context = app(EnrollmentQueryService::class)
        ->receiptContextFor((int) $charge->enrollment_id);

    $payment = $this->recordPayment->execute(
        $this->admin,
        ($this->paymentData)(
            (int) $charge->getKey(),
            Str::uuid()->toString(),
            '400.000',
            [new TenderData(TenderMethod::Cash, '400.000')],
        ),
    );

    $snapshots = DB::table('payment_receipt_snapshots')
        ->where('payment_id', $payment->getKey())
        ->get();

    expect($snapshots)->toHaveCount(1);
    expect((array) $snapshots->sole())->toMatchArray([
        'payment_id' => (int) $payment->getKey(),
        'locale' => 'en',
        'student_code' => $context['student_code'],
        'student_name' => $context['student_name'],
        'enrollment_reference' => $context['enrollment_reference'],
        'course_code' => $context['course_code'],
        'batch_code' => $context['batch_code'],
        'charge_reference' => $charge->reference,
        'list_price' => '1250.000',
        'discount_percentage' => null,
        'final_charge' => '1250.000',
        'amount_paid' => '400.000',
        'remaining_balance' => '850.000',
        'recorded_by_name' => $this->admin->name,
        'payment_reference' => $payment->reference,
    ]);
})->group('receipt');

it('rolls the payment back when its required receipt snapshot cannot be created', function (): void {
    Queue::fake();
    $charge = Charge::factory()->create(['amount' => '1000.000']);
    $originalLocale = app()->getLocale();
    app()->setLocale('locale-is-too-long');

    try {
        expect(fn () => $this->recordPayment->execute(
            $this->admin,
            ($this->paymentData)((int) $charge->getKey(), Str::uuid()->toString()),
        ))->toThrow(QueryException::class);
    } finally {
        app()->setLocale($originalLocale);
    }

    expect(Payment::query()->count())->toBe(0)
        ->and(DB::table('payment_tenders')->count())->toBe(0)
        ->and(DB::table('payment_allocations')->count())->toBe(0)
        ->and(DB::table('payment_receipt_snapshots')->count())->toBe(0);
    Queue::assertNothingPushed();
})->group('receipt');

it('renders delayed receipts from the captured snapshot and restores the worker locale', function (): void {
    app()->setLocale('en');
    $charge = Charge::factory()->create([
        'list_price' => '1400.000',
        'amount' => '1400.000',
    ]);
    $charge->enrollment->student->update([
        'first_name' => 'Snapshot',
        'last_name' => 'Student',
    ]);
    $this->admin->update(['name' => 'Snapshot Operator']);
    $payment = $this->recordPayment->execute(
        $this->admin,
        ($this->paymentData)(
            (int) $charge->getKey(),
            Str::uuid()->toString(),
            '400.000',
            [new TenderData(TenderMethod::Cash, '400.000')],
        ),
    );
    $snapshot = $payment->receiptSnapshot()->firstOrFail();

    $charge->update(['amount' => '1200.000']);
    $charge->enrollment->student->update(['first_name' => 'Changed', 'last_name' => 'Student']);
    $charge->enrollment->batch->update(['code' => 'CHANGED-BATCH']);
    $charge->enrollment->batch->course->update(['code' => 'CHANGED-COURSE']);
    $this->admin->update(['name' => 'Changed Operator']);
    app()->setLocale('ar');

    app()->call([new GenerateReceiptJob((int) $payment->getKey()), 'handle']);

    expect(app()->getLocale())->toBe('ar')
        ->and(ChargeBalance::outstandingFor((int) $charge->getKey())->toDecimal())->toBe('800.000');
    $pdf = Storage::disk('private')->get('receipts/'.$payment->reference.'.pdf');

    foreach ([
        $snapshot->student_name,
        $snapshot->batch_code,
        $snapshot->course_code,
        $snapshot->recorded_by_name,
        '1400.000',
        '1000.000',
    ] as $capturedValue) {
        expect($pdf)->toContain(mb_convert_encoding((string) $capturedValue, 'UTF-16BE', 'UTF-8'));
    }

    foreach (['Changed Student', 'CHANGED-BATCH', 'CHANGED-COURSE', 'Changed Operator', '1200.000'] as $liveValue) {
        expect($pdf)->not->toContain(mb_convert_encoding($liveValue, 'UTF-16BE', 'UTF-8'));
    }
})->group('receipt');

it('keeps payment A captured balance despite a later installment', function (): void {
    $charge = Charge::factory()->create(['amount' => '1834.567']);
    $paymentA = $this->recordPayment->execute(
        $this->admin,
        ($this->paymentData)(
            (int) $charge->getKey(),
            Str::uuid()->toString(),
            '701.234',
            [
                new TenderData(TenderMethod::Card, '201.234', 'AUTH-HISTORIC-A'),
                new TenderData(TenderMethod::Cash, '500.000'),
            ],
        ),
    );
    $paymentA->update(['received_at' => CarbonImmutable::parse('2026-05-10 09:30:00', 'UTC')]);

    $paymentB = $this->recordPayment->execute(
        $this->admin,
        ($this->paymentData)(
            (int) $charge->getKey(),
            Str::uuid()->toString(),
            '200.001',
            [new TenderData(TenderMethod::Cash, '200.001')],
        ),
    );
    $paymentB->update(['received_at' => CarbonImmutable::parse('2026-05-11 09:30:00', 'UTC')]);

    app()->call([new GenerateReceiptJob((int) $paymentA->getKey()), 'handle']);
    $pdf = Storage::disk('private')->get('receipts/'.$paymentA->reference.'.pdf');

    expect($pdf)->toContain(mb_convert_encoding('1133.333', 'UTF-16BE', 'UTF-8'))
        ->not->toContain(mb_convert_encoding('933.332', 'UTF-16BE', 'UTF-8'));
});

it('keeps payment A captured balance despite its later reversal', function (): void {
    $charge = Charge::factory()->create(['amount' => '1834.567']);
    $paymentA = $this->recordPayment->execute(
        $this->admin,
        ($this->paymentData)(
            (int) $charge->getKey(),
            Str::uuid()->toString(),
            '701.234',
            [
                new TenderData(TenderMethod::Card, '201.234', 'AUTH-HISTORIC-A'),
                new TenderData(TenderMethod::Cash, '500.000'),
            ],
        ),
    );
    $receivedAt = CarbonImmutable::parse('2026-05-10 09:30:00', 'UTC');
    $paymentA->update([
        'received_at' => $receivedAt,
        'reversed_at' => $receivedAt->addDay(),
        'reversed_by' => $this->admin->getKey(),
        'reversal_reason' => 'A later correction reversed this payment.',
    ]);

    app()->call([new GenerateReceiptJob((int) $paymentA->getKey()), 'handle']);
    $pdf = Storage::disk('private')->get('receipts/'.$paymentA->reference.'.pdf');

    expect($pdf)->toContain(mb_convert_encoding('1133.333', 'UTF-16BE', 'UTF-8'));
});

it('keeps the earlier captured balance when live payment timestamps later match', function (): void {
    $charge = Charge::factory()->create([
        'list_price' => '2000.000',
        'amount' => '2000.000',
    ]);
    $paymentA = $this->recordPayment->execute(
        $this->admin,
        ($this->paymentData)((int) $charge->getKey(), Str::uuid()->toString(), '100.000', [new TenderData(TenderMethod::Cash, '100.000')]),
    );
    $paymentB = $this->recordPayment->execute(
        $this->admin,
        ($this->paymentData)((int) $charge->getKey(), Str::uuid()->toString(), '200.000', [new TenderData(TenderMethod::Cash, '200.000')]),
    );
    $receivedAt = CarbonImmutable::parse('2026-06-10 09:30:00', 'UTC');
    $paymentA->update(['received_at' => $receivedAt]);
    $paymentB->update(['received_at' => $receivedAt]);

    app()->call([new GenerateReceiptJob((int) $paymentA->getKey()), 'handle']);
    $pdf = Storage::disk('private')->get('receipts/'.$paymentA->reference.'.pdf');

    expect($paymentA->receiptSnapshot()->firstOrFail()->remaining_balance)->toBe('1900.000')
        ->and($pdf)->toContain(mb_convert_encoding('1900.000', 'UTF-16BE', 'UTF-8'))
        ->not->toContain(mb_convert_encoding('1700.000', 'UTF-16BE', 'UTF-8'));
});

it('keeps the target captured balance when an earlier payment is later backdated as reversed', function (): void {
    $charge = Charge::factory()->create([
        'list_price' => '2000.000',
        'amount' => '2000.000',
    ]);
    $earlier = $this->recordPayment->execute(
        $this->admin,
        ($this->paymentData)((int) $charge->getKey(), Str::uuid()->toString(), '100.000', [new TenderData(TenderMethod::Cash, '100.000')]),
    );
    $target = $this->recordPayment->execute(
        $this->admin,
        ($this->paymentData)((int) $charge->getKey(), Str::uuid()->toString(), '200.000', [new TenderData(TenderMethod::Cash, '200.000')]),
    );
    $targetAt = CarbonImmutable::parse('2026-06-10 10:00:00', 'UTC');
    $earlier->update([
        'received_at' => $targetAt->subHour(),
        'reversed_at' => $targetAt->subSecond(),
        'reversed_by' => $this->admin->getKey(),
        'reversal_reason' => 'Reversed before the target payment.',
    ]);
    $target->update(['received_at' => $targetAt]);

    app()->call([new GenerateReceiptJob((int) $target->getKey()), 'handle']);
    $pdf = Storage::disk('private')->get('receipts/'.$target->reference.'.pdf');

    expect($target->receiptSnapshot()->firstOrFail()->remaining_balance)->toBe('1700.000')
        ->and($pdf)->toContain(mb_convert_encoding('1700.000', 'UTF-16BE', 'UTF-8'))
        ->not->toContain(mb_convert_encoding('1800.000', 'UTF-16BE', 'UTF-8'));
});

it('keeps the target captured balance when an earlier reversal is later set at its boundary', function (): void {
    $charge = Charge::factory()->create([
        'list_price' => '2000.000',
        'amount' => '2000.000',
    ]);
    $earlier = $this->recordPayment->execute(
        $this->admin,
        ($this->paymentData)((int) $charge->getKey(), Str::uuid()->toString(), '100.000', [new TenderData(TenderMethod::Cash, '100.000')]),
    );
    $target = $this->recordPayment->execute(
        $this->admin,
        ($this->paymentData)((int) $charge->getKey(), Str::uuid()->toString(), '200.000', [new TenderData(TenderMethod::Cash, '200.000')]),
    );
    $targetAt = CarbonImmutable::parse('2026-06-10 10:00:00', 'UTC');
    $earlier->update([
        'received_at' => $targetAt->subHour(),
        'reversed_at' => $targetAt,
        'reversed_by' => $this->admin->getKey(),
        'reversal_reason' => 'Reversed at the target payment boundary.',
    ]);
    $target->update(['received_at' => $targetAt]);

    app()->call([new GenerateReceiptJob((int) $target->getKey()), 'handle']);
    $pdf = Storage::disk('private')->get('receipts/'.$target->reference.'.pdf');

    expect($target->receiptSnapshot()->firstOrFail()->remaining_balance)->toBe('1700.000')
        ->and($pdf)->toContain(mb_convert_encoding('1700.000', 'UTF-16BE', 'UTF-8'))
        ->not->toContain(mb_convert_encoding('1800.000', 'UTF-16BE', 'UTF-8'));
});

it('keeps the target captured balance when an earlier reversal is later set after it', function (): void {
    $charge = Charge::factory()->create([
        'list_price' => '2000.000',
        'amount' => '2000.000',
    ]);
    $earlier = $this->recordPayment->execute(
        $this->admin,
        ($this->paymentData)((int) $charge->getKey(), Str::uuid()->toString(), '100.000', [new TenderData(TenderMethod::Cash, '100.000')]),
    );
    $target = $this->recordPayment->execute(
        $this->admin,
        ($this->paymentData)((int) $charge->getKey(), Str::uuid()->toString(), '200.000', [new TenderData(TenderMethod::Cash, '200.000')]),
    );
    $targetAt = CarbonImmutable::parse('2026-06-10 10:00:00', 'UTC');
    $earlier->update([
        'received_at' => $targetAt->subHour(),
        'reversed_at' => $targetAt->addSecond(),
        'reversed_by' => $this->admin->getKey(),
        'reversal_reason' => 'Reversed after the target payment.',
    ]);
    $target->update(['received_at' => $targetAt]);

    app()->call([new GenerateReceiptJob((int) $target->getKey()), 'handle']);
    $pdf = Storage::disk('private')->get('receipts/'.$target->reference.'.pdf');

    expect($target->receiptSnapshot()->firstOrFail()->remaining_balance)->toBe('1700.000')
        ->and($pdf)->toContain(mb_convert_encoding('1700.000', 'UTF-16BE', 'UTF-8'))
        ->not->toContain(mb_convert_encoding('1800.000', 'UTF-16BE', 'UTF-8'));
});

it('formats the payment date on the centre calendar at the local-year boundary', function (): void {
    $receivedAt = CarbonImmutable::parse('2026-12-31 22:30:00', 'UTC');
    CarbonImmutable::setTestNow($receivedAt);
    $charge = Charge::factory()->create(['amount' => '1000.000']);

    try {
        $payment = $this->recordPayment->execute(
            $this->admin,
            ($this->paymentData)((int) $charge->getKey(), Str::uuid()->toString()),
        );
    } finally {
        CarbonImmutable::setTestNow();
    }

    app()->call([new GenerateReceiptJob((int) $payment->getKey()), 'handle']);
    $pdf = Storage::disk('private')->get('receipts/'.$payment->reference.'.pdf');

    expect(CentreCalendar::localise($receivedAt)->format('Y-m-d H:i'))->toBe('2027-01-01 00:30')
        ->and($pdf)->toContain(mb_convert_encoding('2027-01-01 00:30', 'UTF-16BE', 'UTF-8'));
});

it('declares bounded attempts and backoff for transient receipt failures', function (): void {
    $job = new GenerateReceiptJob(1);

    expect(property_exists($job, 'tries'))->toBeTrue()
        ->and($job->tries)->toBe(3)
        ->and($job->backoff)->toBe([5, 30, 120])
        ->and($job->timeout)->toBe(60)
        ->and($job->timeout)->toBeLessThan((int) config('queue.connections.database.retry_after'));
});

it('rolls receipt attachment and its semantic activity back together when activity logging fails', function (): void {
    $charge = Charge::factory()->create(['amount' => '1000.000']);
    $payment = $this->recordPayment->execute(
        $this->admin,
        ($this->paymentData)((int) $charge->getKey(), Str::uuid()->toString()),
    );
    $dispatcher = clone DB::getEventDispatcher();
    DB::listen(function ($query): void {
        if (str_contains($query->sql, 'activity_log')) {
            throw new RuntimeException('forced activity failure');
        }
    });

    try {
        app()->call([new GenerateReceiptJob((int) $payment->getKey()), 'handle']);
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('forced activity failure');
    } finally {
        DB::setEventDispatcher($dispatcher);
    }

    $payment->refresh();
    expect($payment->receipt_disk)->toBeNull()
        ->and($payment->receipt_path)->toBeNull()
        ->and(Activity::query()->where('event', 'receipt_generated')->count())->toBe(0);

    $pending = PendingFileDeletion::on(FileLifecycleService::compensationConnectionName())
        ->where('path', 'receipts/'.$payment->reference.'.pdf')
        ->firstOrFail();
    app()->call([new PurgeDeletedFileJob((int) $pending->getKey(), true), 'handle']);

    expect(Storage::disk('private')->allFiles('receipts'))->toBe([]);

    app()->call([new GenerateReceiptJob((int) $payment->getKey()), 'handle']);

    expect($payment->refresh()->receipt_path)->toBe('receipts/'.$payment->reference.'.pdf')
        ->and(Activity::query()->where('event', 'receipt_generated')->count())->toBe(1);
});

it('retries receipt generation successfully after a transient private-storage failure', function (): void {
    $charge = Charge::factory()->create(['amount' => '1000.000']);
    $payment = $this->recordPayment->execute(
        $this->admin,
        ($this->paymentData)((int) $charge->getKey(), Str::uuid()->toString()),
    );
    $disk = config('filesystems.disks.private');
    $blockedRoot = storage_path('framework/testing/receipt-storage-blocker');
    File::put($blockedRoot, 'not a directory');

    try {
        config(['filesystems.disks.private.root' => $blockedRoot]);
        Storage::forgetDisk('private');

        $failed = false;

        try {
            app()->call([new GenerateReceiptJob((int) $payment->getKey()), 'handle']);
        } catch (Throwable) {
            $failed = true;
        }

        expect($failed)->toBeTrue()
            ->and($payment->refresh()->receipt_path)->toBeNull();
    } finally {
        config(['filesystems.disks.private' => $disk]);
        Storage::forgetDisk('private');
        File::delete($blockedRoot);
    }

    app()->call([new GenerateReceiptJob((int) $payment->getKey()), 'handle']);

    expect($payment->refresh()->receipt_path)->toBe('receipts/'.$payment->reference.'.pdf')
        ->and(Activity::query()->where('event', 'receipt_generated')->count())->toBe(1);
});

it('serializes two concurrent receipt attachments so only the winner writes bytes', function (): void {
    $payment = Payment::factory()->create(['recorded_by' => $this->admin->getKey()]);
    $path = 'receipts/'.$payment->reference.'.pdf';
    $storagePath = Storage::disk('private')->path($path);
    $realStoragePath = storage_path('app/secure/'.$path);
    $token = Str::uuid()->toString();
    $readyA = storage_path("framework/testing/receipt-{$token}-a-ready.json");
    $resultA = storage_path("framework/testing/receipt-{$token}-a-result.json");
    $readyB = storage_path("framework/testing/receipt-{$token}-b-ready.json");
    $resultB = storage_path("framework/testing/receipt-{$token}-b-result.json");
    $workers = [
        receiptAttachmentWorker((int) $payment->getKey(), $path, 'receipt-bytes-a', $readyA, $resultA),
        receiptAttachmentWorker((int) $payment->getKey(), $path, 'receipt-bytes-b', $readyB, $resultB),
    ];
    $connection = DB::connection();

    try {
        File::delete($storagePath, $realStoragePath, $readyA, $resultA, $readyB, $resultB);
        $connection->beginTransaction();
        Payment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();

        foreach ($workers as $worker) {
            $worker->start();
        }

        $deadline = microtime(true) + 10;

        while ((! File::exists($readyA) || ! File::exists($readyB)) && microtime(true) < $deadline) {
            usleep(25_000);
        }

        expect(File::exists($readyA))->toBeTrue('Worker A did not reach the Action.')
            ->and(File::exists($readyB))->toBeTrue('Worker B did not reach the Action.');

        usleep(250_000);

        expect($workers[0]->isRunning())->toBeTrue('Worker A was not blocked by the payment-row lock.')
            ->and($workers[1]->isRunning())->toBeTrue('Worker B was not blocked by the payment-row lock.')
            ->and(File::exists($resultA))->toBeFalse()
            ->and(File::exists($resultB))->toBeFalse();

        $connection->commit();

        foreach ($workers as $worker) {
            $worker->wait();
            expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput());
        }

        $results = [
            json_decode((string) File::get($resultA), true, flags: JSON_THROW_ON_ERROR),
            json_decode((string) File::get($resultB), true, flags: JSON_THROW_ON_ERROR),
        ];
        $winner = array_search(true, array_column($results, 'attached'), true);

        expect($winner)->not->toBeFalse()
            ->and(collect($results)->where('attached', true)->count())->toBe(1)
            ->and(collect($results)->where('attached', false)->count())->toBe(1)
            ->and(File::get($storagePath))->toBe($winner === 0 ? 'receipt-bytes-a' : 'receipt-bytes-b')
            ->and(File::exists($realStoragePath))->toBeFalse()
            ->and($payment->refresh()->receipt_path)->toBe($path)
            ->and(Activity::query()->where('event', 'receipt_generated')->count())->toBe(1);
    } finally {
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        foreach ($workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop();
            }
        }

        File::delete($storagePath, $realStoragePath, $readyA, $resultA, $readyB, $resultB);
    }
});

it('does not let failed receipt cleanup delete a concurrent successor receipt', function (): void {
    $payment = Payment::factory()->create(['recorded_by' => $this->admin->getKey()]);
    $path = 'receipts/'.$payment->reference.'.pdf';
    $storagePath = Storage::disk('private')->path($path);
    $realStoragePath = storage_path('app/secure/'.$path);
    $token = Str::uuid()->toString();
    $paths = collect([
        'failure-ready',
        'failure-at-activity',
        'failure-release',
        'failure-result',
        'success-ready',
        'success-result',
    ])->mapWithKeys(fn (string $name): array => [
        $name => storage_path("framework/testing/receipt-{$token}-{$name}.json"),
    ]);
    $failure = failingReceiptAttachmentWorker(
        (int) $payment->getKey(),
        $path,
        'failed-worker-bytes',
        $paths['failure-ready'],
        $paths['failure-at-activity'],
        $paths['failure-release'],
        $paths['success-result'],
        $paths['failure-result'],
    );
    $success = receiptAttachmentWorker(
        (int) $payment->getKey(),
        $path,
        'successful-worker-bytes',
        $paths['success-ready'],
        $paths['success-result'],
    );

    try {
        File::delete($storagePath, $realStoragePath, ...$paths->values()->all());
        $failure->start();
        $deadline = microtime(true) + 10;

        while (! File::exists($paths['failure-at-activity']) && microtime(true) < $deadline) {
            usleep(25_000);
        }

        expect(File::exists($paths['failure-ready']))->toBeTrue('The failing worker did not reach the Action.')
            ->and(File::exists($paths['failure-at-activity']))->toBeTrue('The failing worker did not reach the activity insert.')
            ->and($failure->isRunning())->toBeTrue('The failing worker did not hold its transaction open.')
            ->and(File::get($storagePath))->toBe('failed-worker-bytes');

        $success->start();
        $deadline = microtime(true) + 10;

        while (! File::exists($paths['success-ready']) && microtime(true) < $deadline) {
            usleep(25_000);
        }

        expect(File::exists($paths['success-ready']))->toBeTrue('The successor did not reach the Action.');

        usleep(250_000);

        expect($success->isRunning())->toBeTrue('The successor did not wait on the failing worker\'s payment lock.')
            ->and(File::exists($paths['success-result']))->toBeFalse();

        File::put($paths['failure-release'], 'release');
        $failure->wait();
        $success->wait();

        expect($failure->isSuccessful())->toBeTrue($failure->getErrorOutput())
            ->and($success->isSuccessful())->toBeTrue($success->getErrorOutput());

        $failureResult = json_decode((string) File::get($paths['failure-result']), true, flags: JSON_THROW_ON_ERROR);
        $successResult = json_decode((string) File::get($paths['success-result']), true, flags: JSON_THROW_ON_ERROR);

        expect($failureResult)->toMatchArray([
            'outcome' => 'failed',
            'message' => 'forced concurrent activity failure',
        ])->and($successResult)->toMatchArray([
            'attached' => true,
        ])->and(File::get($storagePath))->toBe('successful-worker-bytes')
            ->and(File::exists($realStoragePath))->toBeFalse()
            ->and($payment->refresh()->receipt_path)->toBe($path)
            ->and(Activity::query()->where('event', 'receipt_generated')->count())->toBe(1);
    } finally {
        foreach ([$failure, $success] as $worker) {
            if ($worker->isRunning()) {
                $worker->stop();
            }
        }

        File::delete($storagePath, $realStoragePath, ...$paths->values()->all());
    }
});

it('queues exactly one receipt after a finalized payment and its replay', function (): void {
    Queue::fake();
    $discount = Discount::factory()->create(['percentage' => '20.00']);

    $charge = Charge::factory()->create([
        'list_price' => '1250.000',
        'discount_id' => $discount->getKey(),
        'discount_percentage' => '20.00',
        'amount' => '1000.000',
    ]);
    $key = Str::uuid()->toString();

    $first = $this->recordPayment->execute($this->admin, ($this->paymentData)((int) $charge->getKey(), $key));
    $replay = $this->recordPayment->execute($this->admin, ($this->paymentData)((int) $charge->getKey(), $key));

    expect((int) $replay->getKey())->toBe((int) $first->getKey());

    expect(DB::table('payment_receipt_snapshots')
        ->where('payment_id', $first->getKey())
        ->count())->toBe(1);

    Queue::assertPushed(GenerateReceiptJob::class, function (GenerateReceiptJob $job) use ($first): bool {
        return $job->paymentId === (int) $first->getKey();
    });
    Queue::assertPushed(GenerateReceiptJob::class, 1);
});

it('runs synchronous receipt generation only after the outer payment transaction commits', function (): void {
    $charge = Charge::factory()->create(['amount' => '1000.000']);
    $originalQueueConnection = config('queue.default');
    config(['queue.default' => 'sync']);

    try {
        DB::beginTransaction();

        try {
            $rolledBack = $this->recordPayment->execute(
                $this->admin,
                ($this->paymentData)((int) $charge->getKey(), Str::uuid()->toString()),
            );
            $rolledBackPath = app(ReceiptLocation::class)->path($rolledBack);

            expect($rolledBack->receiptSnapshot()->count())->toBe(1)
                ->and($rolledBack->receipt_path)->toBeNull()
                ->and(Storage::disk('private')->missing($rolledBackPath))->toBeTrue()
                ->and(Activity::query()->where('event', 'receipt_generated')->count())->toBe(0);

            DB::rollBack();
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }

        expect(Payment::query()->count())->toBe(0)
            ->and(DB::table('payment_receipt_snapshots')->count())->toBe(0)
            ->and(Storage::disk('private')->missing($rolledBackPath))->toBeTrue()
            ->and(Activity::query()->where('event', 'receipt_generated')->count())->toBe(0);

        DB::beginTransaction();

        $committed = $this->recordPayment->execute(
            $this->admin,
            ($this->paymentData)((int) $charge->getKey(), Str::uuid()->toString()),
        );
        $committedPath = app(ReceiptLocation::class)->path($committed);

        expect($committed->receipt_path)->toBeNull()
            ->and(Storage::disk('private')->missing($committedPath))->toBeTrue()
            ->and(Activity::query()->where('event', 'receipt_generated')->count())->toBe(0);

        DB::commit();

        expect($committed->refresh()->receipt_path)->toBe($committedPath)
            ->and(Storage::disk('private')->exists($committedPath))->toBeTrue()
            ->and(Activity::query()->where('event', 'receipt_generated')->count())->toBe(1);
    } finally {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        config(['queue.default' => $originalQueueConnection]);
    }
});

it('refuses to replace a payment receipt location that is already attached', function (): void {
    $payment = Payment::factory()->create();
    $path = app(ReceiptLocation::class)->path($payment);
    $payment->update(['receipt_disk' => 'private', 'receipt_path' => $path]);
    Storage::disk('private')->put($path, 'original-receipt-bytes');

    $attached = app(AttachReceiptAction::class)->execute(
        (int) $payment->getKey(),
        $path,
        'replacement-receipt-bytes',
    );

    expect($attached)->toBeFalse()
        ->and($payment->refresh()->receipt_path)->toBe($path)
        ->and(Storage::disk('private')->get($path))->toBe('original-receipt-bytes');
});

it('refuses a non-canonical receipt path before writing bytes', function (): void {
    $payment = Payment::factory()->create();
    $path = 'receipts/../staff-certificates/canary.pdf';

    expect(fn () => app(AttachReceiptAction::class)->execute(
        (int) $payment->getKey(),
        $path,
        'must-not-be-written',
    ))->toThrow(RuntimeException::class, 'non-canonical receipt location');

    expect($payment->refresh()->receipt_disk)->toBeNull()
        ->and($payment->receipt_path)->toBeNull();
    Storage::disk('private')->assertMissing($path);
});

it('uses renderer-supported direction-neutral receipt layout primitives', function (): void {
    $template = File::get(resource_path('views/finance/receipt.blade.php'));

    expect(preg_match(
        '/(?:inline-size|margin-block-end|padding-block|padding-inline|text-align:\s*(?:start|end))/',
        $template,
    ))->toBe(0)
        ->and($template)->toContain('<table width="100%">')
        ->and($template)->toContain('padding: 6px 8px;')
        ->and($template)->toContain('dir="ltr"');
});

it('renders translated student and tender composites with locale-controlled ordering and separators', function (): void {
    $originalLocale = app()->getLocale();
    app()->setLocale('en');
    Lang::get('receipt.title');
    $originalStudentIdentity = Lang::get('receipt.student_identity', [], 'en');
    $originalTenderLabel = Lang::get('receipt.tender_label', [], 'en');
    Lang::addLines([
        'receipt.student_identity' => 'NAME:nameZCODE:code',
        'receipt.tender_label' => 'METHOD:methodZTENDER',
    ], 'en');

    try {
        $charge = Charge::factory()->create(['amount' => '1000.000']);
        $charge->enrollment->student->update([
            'first_name' => 'A',
            'last_name' => 'B',
            'student_code' => 'STU-C',
        ]);
        $payment = $this->recordPayment->execute(
            $this->admin,
            ($this->paymentData)((int) $charge->getKey(), Str::uuid()->toString()),
        );

        app()->call([new GenerateReceiptJob((int) $payment->getKey()), 'handle']);

        $context = app(EnrollmentQueryService::class)
            ->receiptContextFor((int) $charge->enrollment_id);
        $pdf = Storage::disk('private')->get('receipts/'.$payment->reference.'.pdf');

        $translatedStudentPrefix = mb_convert_encoding(
            "NAME{$context['student_name']}ZCODE",
            'UTF-16BE',
            'UTF-8',
        );
        $isolatedStudentCode = mb_convert_encoding($context['student_code'], 'UTF-16BE', 'UTF-8');

        expect($pdf)->toContain($translatedStudentPrefix)
            ->and($pdf)->toContain($isolatedStudentCode)
            ->and(strpos($pdf, $translatedStudentPrefix))->toBeLessThan(strpos($pdf, $isolatedStudentCode));

        foreach (['METHODCardZTENDER', 'METHODCashZTENDER'] as $translatedComposite) {
            expect($pdf)->toContain(mb_convert_encoding($translatedComposite, 'UTF-16BE', 'UTF-8'));
        }

        expect($pdf)->not->toContain(mb_convert_encoding(
            "{$context['student_code']} — {$context['student_name']}",
            'UTF-16BE',
            'UTF-8',
        ))->not->toContain(mb_convert_encoding('Tender: Card', 'UTF-16BE', 'UTF-8'));
    } finally {
        Lang::addLines([
            'receipt.student_identity' => $originalStudentIdentity,
            'receipt.tender_label' => $originalTenderLabel,
        ], 'en');
        app()->setLocale($originalLocale);
    }
});

it('renders the Arabic-locale fallback in right-to-left direction and restores English', function (): void {
    app()->setLocale('ar');
    $charge = Charge::factory()->create(['amount' => '1000.000']);
    $payment = $this->recordPayment->execute(
        $this->admin,
        ($this->paymentData)((int) $charge->getKey(), Str::uuid()->toString()),
    );
    app()->setLocale('en');

    app()->call([new GenerateReceiptJob((int) $payment->getKey()), 'handle']);

    expect(app()->getLocale())->toBe('en');
    $pdf = Storage::disk('private')->get('receipts/'.$payment->reference.'.pdf');

    expect($pdf)->toContain('/Direction /R2L')
        ->and($pdf)->toContain(mb_convert_encoding('Payment receipt', 'UTF-16BE', 'UTF-8'));
});

it('renders every receipt field into a private PDF and is retry-safe', function (): void {
    $discount = Discount::factory()->create(['percentage' => '20.00']);

    $charge = Charge::factory()->create([
        'list_price' => '1867.321',
        'discount_id' => $discount->getKey(),
        'discount_percentage' => '17.77',
        'amount' => '1535.538',
    ]);
    $charge->enrollment->student->update([
        'first_name' => 'Rendered',
        'last_name' => 'Student',
    ]);
    $this->admin->update(['name' => 'Receipt Operator']);
    $payment = $this->recordPayment->execute(
        $this->admin,
        ($this->paymentData)(
            (int) $charge->getKey(),
            Str::uuid()->toString(),
            '703.219',
            [
                new TenderData(TenderMethod::Card, '201.123', 'AUTH-RENDERED-CARD'),
                new TenderData(TenderMethod::Cash, '502.096'),
            ],
        ),
    );

    app()->call([new GenerateReceiptJob((int) $payment->getKey()), 'handle']);
    app()->call([new GenerateReceiptJob((int) $payment->getKey()), 'handle']);

    $payment->refresh();
    $charge->refresh();
    $context = app(EnrollmentQueryService::class)
        ->receiptContextFor((int) $charge->enrollment_id);

    expect($payment->receipt_disk)->toBe('private')
        ->and($payment->receipt_path)->toBe('receipts/'.$payment->reference.'.pdf')
        ->and(Storage::disk('private')->exists((string) $payment->receipt_path))->toBeTrue();
    expect(Storage::disk('private')->allFiles('receipts'))->toBe([$payment->receipt_path]);

    $pdf = Storage::disk('private')->get((string) $payment->receipt_path);

    expect($pdf)->toStartWith('%PDF-');

    foreach ([
        $payment->reference,
        $context['student_code'],
        $context['student_name'],
        $context['enrollment_reference'],
        $context['course_code'],
        $context['batch_code'],
        $charge->reference,
        '1867.321',
        '17.77',
        '1535.538',
        '703.219',
        '201.123',
        '502.096',
        '832.319',
        'Tender: Card',
        'Tender: Cash',
        CentreCalendar::localise($payment->received_at)->format('Y-m-d H:i'),
        $this->admin->name,
    ] as $requiredField) {
        expect($pdf)->toContain(mb_convert_encoding((string) $requiredField, 'UTF-16BE', 'UTF-8'));
    }

    expect($pdf)->not->toContain('/Direction /R2L');

    expect(Activity::query()
        ->where('subject_type', Payment::class)
        ->where('subject_id', $payment->getKey())
        ->where('event', 'receipt_generated')
        ->count())->toBe(1);
});
