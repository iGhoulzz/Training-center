<?php

declare(strict_types=1);

use App\Domain\Staff\Actions\DeleteUserAction;
use App\Domain\Staff\Exceptions\LastSuperAdminException;
use App\Domain\Staff\Services\SuperAdminInvariantService;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * SuperAdminInvariantService is guard 3 — at least one active super admin must
 * always survive. These tests prove the three properties the guard depends on:
 * it refuses the violating mutation, it does so atomically (a rejected mutation
 * is rolled back, leaving the database untouched), and the survivor check and
 * the mutation run inside ONE transaction that first takes the row lock.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    $this->service = app(SuperAdminInvariantService::class);

    $this->superAdmin = User::factory()->create();
    $this->superAdmin->assignRole('super_admin');
});

it('refuses a mutation that would remove the last active super admin', function () {
    expect(fn () => $this->service->protect(fn () => $this->superAdmin->delete()))
        ->toThrow(LastSuperAdminException::class);
});

it('rolls the mutation back on rejection, leaving the database unchanged', function () {
    try {
        $this->service->protect(fn () => $this->superAdmin->delete());
    } catch (LastSuperAdminException) {
        // expected
    }

    // The soft delete was rolled back — only possible if the delete and the
    // survivor check share a transaction.
    expect(User::find($this->superAdmin->id))->not->toBeNull()
        ->and($this->superAdmin->fresh()->hasRole('super_admin'))->toBeTrue();
});

it('does not count a soft-deleted super admin as a survivor', function () {
    $second = User::factory()->create();
    $second->assignRole('super_admin');
    $second->delete();

    expect(fn () => $this->service->protect(fn () => $this->superAdmin->delete()))
        ->toThrow(LastSuperAdminException::class);
});

it('does not count a deactivated super admin as a survivor', function () {
    $inactive = User::factory()->create(['is_active' => false]);
    $inactive->assignRole('super_admin');

    expect(fn () => $this->service->protect(fn () => $this->superAdmin->delete()))
        ->toThrow(LastSuperAdminException::class);
});

it('commits the mutation when an active survivor remains', function () {
    $second = User::factory()->create();
    $second->assignRole('super_admin');

    $this->service->protect(fn () => $this->superAdmin->delete());

    expect(User::find($this->superAdmin->id))->toBeNull()
        ->and($second->fresh()->isSuperAdmin())->toBeTrue();
});

it('runs the mutation and the survivor check inside one locked transaction', function () {
    $outerLevel = DB::transactionLevel();
    $innerLevel = null;

    DB::connection()->flushQueryLog();
    DB::connection()->enableQueryLog();

    try {
        $this->service->protect(function () use (&$innerLevel): void {
            $innerLevel = DB::transactionLevel();
            $this->superAdmin->delete();
        });
    } catch (LastSuperAdminException) {
        // expected — this is the last super admin
    }

    $queries = collect(DB::connection()->getQueryLog())
        ->pluck('query')
        ->map(fn (string $q): string => strtolower($q));

    DB::connection()->disableQueryLog();

    // The mutation ran exactly one transaction level deeper than the caller —
    // i.e. inside the transaction protect() opened, the same one the survivor
    // check and the row lock live in.
    expect($innerLevel)->toBe($outerLevel + 1)
        // The super-admin row was locked FOR UPDATE before the count.
        ->and($queries->contains(fn (string $q): bool => str_contains($q, 'for update')))->toBeTrue()
        // And because it is one transaction, the rejected delete was rolled back.
        ->and(User::find($this->superAdmin->id))->not->toBeNull();
});

it('is the path the DeleteUserAction takes for a super-admin target', function () {
    // A survivor so the delete is allowed to commit; we only care that the
    // Action routes the write through the locking invariant service.
    $actor = User::factory()->create();
    $actor->assignRole('super_admin');

    DB::connection()->flushQueryLog();
    DB::connection()->enableQueryLog();

    app(DeleteUserAction::class)->execute($actor, $this->superAdmin);

    $tookLock = collect(DB::connection()->getQueryLog())
        ->pluck('query')
        ->contains(fn (string $q): bool => str_contains(strtolower($q), 'for update'));

    DB::connection()->disableQueryLog();

    expect($tookLock)->toBeTrue()
        ->and(User::find($this->superAdmin->id))->toBeNull();
});
