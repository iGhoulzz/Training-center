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
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

uses(DatabaseTruncation::class);

afterAll(function (): void {
    RefreshDatabaseState::$migrated = false;
});

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    Storage::fake('private');

    $admin = User::factory()->create(['is_active' => true]);
    app(SystemRoleWriter::class)->assignRoles($admin, 'admin');

    $this->admin = $admin->refresh();
    $this->recordPayment = app(RecordPaymentAction::class);
    $this->paymentData = fn (int $chargeId, string $key): RecordPaymentData => new RecordPaymentData(
        chargeId: $chargeId,
        allocation: '1000.000',
        tenders: [
            new TenderData(TenderMethod::Card, '300.000', 'AUTH-1234567890'),
            new TenderData(TenderMethod::Cash, '700.000'),
        ],
        idempotencyKey: $key,
    );
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
    );

    expect($attached)->toBeFalse()
        ->and($payment->refresh()->receipt_path)->toBe('receipts/RCT-2026-000001.pdf');
});

it('renders every receipt field into a private PDF and is retry-safe', function (): void {
    $discount = Discount::factory()->create(['percentage' => '20.00']);

    $charge = Charge::factory()->create([
        'list_price' => '1250.000',
        'discount_id' => $discount->getKey(),
        'discount_percentage' => '20.00',
        'amount' => '1000.000',
    ]);
    $payment = $this->recordPayment->execute(
        $this->admin,
        ($this->paymentData)((int) $charge->getKey(), Str::uuid()->toString()),
    );

    app()->call([new GenerateReceiptJob((int) $payment->getKey()), 'handle']);
    app()->call([new GenerateReceiptJob((int) $payment->getKey()), 'handle']);

    $payment->refresh();
    $charge->refresh();
    $context = app(EnrollmentQueryService::class)
        ->receiptContextFor((int) $charge->enrollment_id);

    expect($payment->receipt_disk)->toBe('private')
        ->and($payment->receipt_path)->toStartWith('receipts/')
        ->and(Storage::disk('private')->exists((string) $payment->receipt_path))->toBeTrue();

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
        '1250.000',
        '20.00',
        '1000.000',
        '300.000',
        '700.000',
        '0.000',
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
