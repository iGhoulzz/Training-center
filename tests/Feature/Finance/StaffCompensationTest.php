<?php

declare(strict_types=1);

use App\Domain\Finance\Models\StaffCompensation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| StaffCompensation::user() — withTrashed(), behaviourally
|--------------------------------------------------------------------------
|
| The class docblock on user() explains why the relation needs withTrashed():
| users soft-delete while `staff_compensation.user_id` restricts on delete, so a
| departed employee's historical compensation row outlives the account. Without
| withTrashed() the SoftDeletes global scope resolves the relation to null on
| exactly the rows a payroll history hangs off — a frozen rate that can no longer
| explain whose wage it was.
|
| Following StaffProfileTest's "still resolves the account behind a profile
| after the user is soft deleted" — the same shape, for the same reason, on a
| different foreign key.
*/

it('still resolves the employee behind a compensation row after the user is soft deleted', function () {
    $user = User::factory()->create(['name' => 'Departed Instructor']);
    $compensation = StaffCompensation::factory()->create(['user_id' => $user->getKey()]);

    $user->delete();

    $compensation = $compensation->fresh();

    expect($compensation->user)->not->toBeNull()
        ->and($compensation->user->name)->toBe('Departed Instructor')
        ->and($compensation->user->trashed())->toBeTrue();
})->group('finance');
