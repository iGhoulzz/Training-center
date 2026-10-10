<?php

declare(strict_types=1);

use App\Domain\Enrollment\Models\Batch;
use App\Domain\Staff\Queries\AssignedHoursPerUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('counts assigned batches and hours including closed batches and departed instructors', function () {
    $user = User::factory()->create();
    $departed = User::factory()->create(['is_active' => false]);
    $departed->delete();
    Batch::factory()->completed()->create()->instructors()->attach($user, ['assigned_hours' => 12]);
    $batch = Batch::factory()->cancelled()->create();
    $batch->instructors()->attach($user, ['assigned_hours' => 18]);
    $batch->instructors()->attach($departed, ['assigned_hours' => 7]);
    $unassigned = User::factory()->create();

    $rows = app(AssignedHoursPerUser::class)->totals();
    expect($rows[$user->id])->toBe(['batch_count' => 2, 'assigned_hours' => 30])
        ->and($rows[$departed->id])->toBe(['batch_count' => 1, 'assigned_hours' => 7])
        ->and($rows->has($unassigned->id))->toBeFalse();
});

it('loads one or twenty users in one statement', function () {
    $counts = [];
    foreach ([1, 20] as $size) {
        for ($i = 0; $i < ($size === 1 ? 1 : 19); $i++) {
            Batch::factory()->create()->instructors()->attach(User::factory()->create(), ['assigned_hours' => 15]);
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        expect(app(AssignedHoursPerUser::class)->totals())->toHaveCount($size);
        $counts[] = count(DB::getQueryLog());
        DB::disableQueryLog();
    }
    expect($counts)->toBe([1, 1]);
});
