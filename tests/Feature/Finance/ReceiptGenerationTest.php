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
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Models\User;
use App\Support\CentreCalendar;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
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
function receiptWorkerEnvironment(): array
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
    ];
}

function receiptAttachmentWorker(int $paymentId, string $path, string $bytes, string $resultPath): Process
{
    $script = <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        [$script, $paymentId, $path, $bytes, $resultPath] = $_SERVER['argv'];
        $attached = app(App\Domain\Finance\Actions\AttachReceiptAction::class)->execute(
            (int) $paymentId,
            $path,
            $bytes,
        );
        file_put_contents($resultPath, json_encode(['attached' => $attached], JSON_THROW_ON_ERROR));
        PHP;

    return new Process(
        [PHP_BINARY, '-r', $script, (string) $paymentId, $path, $bytes, $resultPath],
        base_path(),
        receiptWorkerEnvironment(),
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

it('prints payment A remaining balance as of payment A despite a later installment', function (): void {
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

it('prints payment A remaining balance as of payment A despite its later reversal', function (): void {
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

it('formats the payment date on the centre calendar at the local-year boundary', function (): void {
    $charge = Charge::factory()->create(['amount' => '1000.000']);
    $payment = $this->recordPayment->execute(
        $this->admin,
        ($this->paymentData)((int) $charge->getKey(), Str::uuid()->toString()),
    );
    $receivedAt = CarbonImmutable::parse('2026-12-31 22:30:00', 'UTC');
    $payment->update(['received_at' => $receivedAt]);

    app()->call([new GenerateReceiptJob((int) $payment->getKey()), 'handle']);
    $pdf = Storage::disk('private')->get('receipts/'.$payment->reference.'.pdf');

    expect(CentreCalendar::localise($receivedAt)->format('Y-m-d H:i'))->toBe('2027-01-01 00:30')
        ->and($pdf)->toContain(mb_convert_encoding('2027-01-01 00:30', 'UTF-16BE', 'UTF-8'));
});

it('declares bounded attempts and backoff for transient receipt failures', function (): void {
    $job = new GenerateReceiptJob(1);

    expect(property_exists($job, 'tries'))->toBeTrue()
        ->and($job->tries)->toBe(3)
        ->and($job->backoff)->toBe([5, 30, 120]);
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
        ->and(Storage::disk('private')->allFiles('receipts'))->toBe([])
        ->and(Activity::query()->where('event', 'receipt_generated')->count())->toBe(0);

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
    $storagePath = storage_path('app/secure/'.$path);
    $token = Str::uuid()->toString();
    $resultA = storage_path("framework/testing/receipt-{$token}-a.json");
    $resultB = storage_path("framework/testing/receipt-{$token}-b.json");
    $workers = [
        receiptAttachmentWorker((int) $payment->getKey(), $path, 'receipt-bytes-a', $resultA),
        receiptAttachmentWorker((int) $payment->getKey(), $path, 'receipt-bytes-b', $resultB),
    ];
    $connection = DB::connection();

    try {
        File::delete($storagePath, $resultA, $resultB);
        $connection->beginTransaction();
        Payment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();

        foreach ($workers as $worker) {
            $worker->start();
        }

        usleep(500_000);
        expect($workers[0]->isRunning())->toBeTrue()
            ->and($workers[1]->isRunning())->toBeTrue();

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

        File::delete($storagePath, $resultA, $resultB);
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

    Queue::assertPushed(GenerateReceiptJob::class, function (GenerateReceiptJob $job) use ($first): bool {
        return $job->paymentId === (int) $first->getKey();
    });
    Queue::assertPushed(GenerateReceiptJob::class, 1);
});

it('does not queue receipt generation when the finalized payment transaction rolls back', function (): void {
    $charge = Charge::factory()->create(['amount' => '1000.000']);
    $originalQueueConnection = config('queue.default');
    config(['queue.default' => 'database']);
    $jobsBefore = DB::table('jobs')->count();

    try {
        DB::beginTransaction();

        try {
            $this->recordPayment->execute(
                $this->admin,
                ($this->paymentData)((int) $charge->getKey(), Str::uuid()->toString()),
            );

            DB::rollBack();
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
    } finally {
        config(['queue.default' => $originalQueueConnection]);
    }

    expect(Payment::query()->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe($jobsBefore);
});

it('refuses to replace a payment receipt location that is already attached', function (): void {
    $payment = Payment::factory()->create([
        'receipt_disk' => 'private',
        'receipt_path' => 'receipts/RCT-2026-000001.pdf',
    ]);

    $attached = app(AttachReceiptAction::class)->execute(
        (int) $payment->getKey(),
        'receipts/RCT-2026-000002.pdf',
        'replacement-receipt-bytes',
    );

    expect($attached)->toBeFalse()
        ->and($payment->refresh()->receipt_path)->toBe('receipts/RCT-2026-000001.pdf')
        ->and(Storage::disk('private')->exists('receipts/RCT-2026-000002.pdf'))->toBeFalse();
});

it('renders every receipt field into a private PDF and is retry-safe', function (): void {
    $discount = Discount::factory()->create(['percentage' => '20.00']);

    $charge = Charge::factory()->create([
        'list_price' => '1867.321',
        'discount_id' => $discount->getKey(),
        'discount_percentage' => '17.77',
        'amount' => '1535.538',
    ]);
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

    expect($pdf)->toContain('/Direction /R2L');

    expect(Activity::query()
        ->where('subject_type', Payment::class)
        ->where('subject_id', $payment->getKey())
        ->where('event', 'receipt_generated')
        ->count())->toBe(1);
});
