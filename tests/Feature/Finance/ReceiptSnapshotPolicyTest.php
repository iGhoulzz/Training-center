<?php

declare(strict_types=1);

use App\Domain\Finance\Models\PaymentReceiptSnapshot;
use App\Domain\Finance\Policies\PaymentReceiptSnapshotPolicy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

it('discovers a policy that refuses the complete direct snapshot surface', function (
    string $ability,
    bool $needsRecord,
): void {
    $actor = User::factory()->create();
    $snapshot = PaymentReceiptSnapshot::factory()->create();

    expect(Gate::getPolicyFor(PaymentReceiptSnapshot::class))
        ->toBeInstanceOf(PaymentReceiptSnapshotPolicy::class);
    expect(Gate::forUser($actor)->allows(
        $ability,
        $needsRecord ? $snapshot : PaymentReceiptSnapshot::class,
    ))->toBeFalse();
})->with([
    'viewAny' => ['viewAny', false],
    'view' => ['view', true],
    'create' => ['create', false],
    'update' => ['update', true],
    'delete' => ['delete', true],
    'deleteAny' => ['deleteAny', false],
    'restore' => ['restore', true],
    'restoreAny' => ['restoreAny', false],
    'forceDelete' => ['forceDelete', true],
    'forceDeleteAny' => ['forceDeleteAny', false],
    'replicate' => ['replicate', true],
    'reorder' => ['reorder', false],
]);
