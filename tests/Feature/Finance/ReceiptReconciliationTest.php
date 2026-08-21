<?php

declare(strict_types=1);

use App\Domain\Finance\Actions\ReconcilePendingReceiptsAction;
use App\Domain\Finance\Jobs\GenerateReceiptJob;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Models\PaymentReceiptSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-08-21 12:00:00');
    Queue::fake();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('recovers an old missing receipt but leaves a newborn snapshot to its normal job', function (): void {
    $old = PaymentReceiptSnapshot::factory()->create([
        'created_at' => now()->subMinutes(11),
        'updated_at' => now()->subMinutes(11),
    ]);
    $newborn = PaymentReceiptSnapshot::factory()->create([
        'created_at' => now()->subMinutes(9),
        'updated_at' => now()->subMinutes(9),
    ]);

    $this->artisan('receipts:reconcile-pending')->assertSuccessful();

    Queue::assertPushed(
        GenerateReceiptJob::class,
        fn (GenerateReceiptJob $job): bool => $job->paymentId === $old->payment_id,
    );
    Queue::assertNotPushed(
        GenerateReceiptJob::class,
        fn (GenerateReceiptJob $job): bool => $job->paymentId === $newborn->payment_id,
    );
});

it('puts a never-attempted tail first when old attempts become eligible again', function (): void {
    $snapshots = PaymentReceiptSnapshot::factory()
        ->count(101)
        ->create([
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);
    $tailPaymentId = (int) $snapshots->last()->payment_id;

    expect(app(ReconcilePendingReceiptsAction::class)->execute())->toBe(100);
    Queue::assertNotPushed(
        GenerateReceiptJob::class,
        fn (GenerateReceiptJob $job): bool => $job->paymentId === $tailPaymentId,
    );

    Queue::fake();
    CarbonImmutable::setTestNow(now()->addMinutes(11));

    expect(app(ReconcilePendingReceiptsAction::class)->execute())->toBe(100);
    Queue::assertPushed(
        GenerateReceiptJob::class,
        fn (GenerateReceiptJob $job): bool => $job->paymentId === $tailPaymentId,
    );
});

it('skips a payment whose receipt appeared before the locked recheck', function (): void {
    $snapshot = PaymentReceiptSnapshot::factory()->create([
        'created_at' => now()->subHour(),
        'updated_at' => now()->subHour(),
    ]);
    $receiptAppeared = false;
    DB::listen(function (QueryExecuted $query) use ($snapshot, &$receiptAppeared): void {
        if (
            $receiptAppeared
            || ! str_contains($query->sql, 'payment_receipt_snapshots')
            || ! str_contains($query->sql, 'payments')
        ) {
            return;
        }

        $receiptAppeared = true;
        Payment::query()->whereKey($snapshot->payment_id)->update([
            'receipt_disk' => 'private',
            'receipt_path' => 'receipts/'.$snapshot->payment_reference.'.pdf',
        ]);
    });

    expect(app(ReconcilePendingReceiptsAction::class)->execute())->toBe(0)
        ->and($receiptAppeared)->toBeTrue();
    Queue::assertNothingPushed();
});

it('stamps failed dispatches and continues when reporting also fails', function (): void {
    $snapshots = PaymentReceiptSnapshot::factory()
        ->count(2)
        ->create([
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);
    $originalHandler = app(ExceptionHandler::class);
    $throwingHandler = Mockery::mock(ExceptionHandler::class);
    $throwingHandler->shouldReceive('report')->twice()->andThrow(new RuntimeException('log disk unavailable'));
    app()->instance(ExceptionHandler::class, $throwingHandler);
    Bus::shouldReceive('dispatch')
        ->twice()
        ->andThrow(new RuntimeException('queue unavailable'));

    try {
        expect(app(ReconcilePendingReceiptsAction::class)->execute())->toBe(0);
    } finally {
        app()->instance(ExceptionHandler::class, $originalHandler);
    }

    expect($snapshots->map(fn (PaymentReceiptSnapshot $snapshot) => $snapshot->refresh()->last_reconciliation_attempt_at))
        ->each->not->toBeNull();
});

it('registers five-minute reconciliation with a fifteen-minute mutex', function (): void {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event): bool => str_contains((string) $event->command, 'receipts:reconcile-pending'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/5 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue()
        ->and($event->expiresAt)->toBe(15);
});
