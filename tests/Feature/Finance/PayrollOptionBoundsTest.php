<?php

declare(strict_types=1);

use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Course;
use App\Domain\Finance\Filament\Resources\PayrollRunResource\Pages\CreatePayrollRun;
use App\Domain\Finance\Filament\Resources\StaffCompensationResource\Pages\CreateStaffCompensation;
use App\Domain\Finance\Models\PayrollLine;
use App\Domain\Finance\Models\PayrollRun;
use App\Domain\Finance\Models\StaffCompensation;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Domain\Staff\Filament\Resources\StaffProfileResource\Pages\CreateStaffProfile;
use App\Domain\Staff\Models\StaffProfile;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('bounds staff owner pickers and redisplays a departed employee', function () {
    $this->seed(RolePermissionSeeder::class);

    $actor = User::factory()->create(['is_active' => true]);
    app(SystemRoleWriter::class)->assignRoles($actor, 'super_admin');

    $profiles = StaffProfile::factory()->count(2_000)->create();
    $profiles->take(30)->each(function (StaffProfile $profile, int $index): void {
        $profile->user->update([
            'name' => 'Needle Employee '.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
        ]);
    });
    $departed = $profiles->last()->user;
    $departed->update(['name' => 'Departed Employee']);
    $departed->delete();

    $profilePage = Livewire::actingAs($actor)->test(CreateStaffProfile::class);
    $profileField = $profilePage->instance()->getSchema('form')?->getComponent('user_id');
    $compensationPage = Livewire::actingAs($actor)->test(CreateStaffCompensation::class);
    $compensationField = $compensationPage->instance()->getSchema('form')?->getComponent('user_id');

    expect($profileField)->toBeInstanceOf(Select::class)
        ->and($compensationField)->toBeInstanceOf(Select::class);

    /** @var Select $profileField */
    $profileResults = $profileField->getSearchResults('Needle Employee');
    $profileField->state($departed->getKey());

    /** @var Select $compensationField */
    $compensationResults = $compensationField->getSearchResults('Needle Employee');
    $compensationField->state($departed->getKey());

    expect($profileResults)->toHaveCount(25)
        ->and(array_values($profileResults))->toContain('Needle Employee 00')
        ->and($profileField->getOptionLabel())->toBe('Departed Employee')
        ->and($compensationResults)->toHaveCount(25)
        ->and(array_values($compensationResults))->toContain('Needle Employee 00')
        ->and($compensationField->getOptionLabel())->toBe('Departed Employee');
});

it('bounds correction target search and redisplays a finalized line', function () {
    $this->seed(RolePermissionSeeder::class);

    $actor = User::factory()->create(['is_active' => true]);
    app(SystemRoleWriter::class)->assignRoles($actor, 'super_admin');

    $employee = User::factory()->create(['name' => 'Needle Correction']);
    $compensation = StaffCompensation::factory()->create(['user_id' => $employee->getKey()]);
    $run = PayrollRun::factory()->create();

    $lines = PayrollLine::factory()
        ->count(2_000)
        ->for($run, 'run')
        ->for($employee)
        ->finalized()
        ->sequence(function ($sequence): array {
            $month = now()->startOfMonth()->subMonths($sequence->index + 1);

            return [
                'segment_start' => $month->toDateString(),
                'segment_end' => $month->endOfMonth()->toDateString(),
                'frozen_days' => $month->daysInMonth,
                'frozen_days_in_month' => $month->daysInMonth,
            ];
        })
        ->create(['staff_compensation_id' => $compensation->getKey()]);

    $selected = $lines->last();
    $component = Livewire::actingAs($actor)->test(CreatePayrollRun::class);
    $field = $component->instance()->getSchema('form')?->getComponent('target_line_id', withHidden: true);

    expect($field)->toBeInstanceOf(Select::class);

    /** @var Select $field */
    $searchResults = $field->getSearchResults('Needle Correction');
    $field->state($selected->getKey());

    expect($searchResults)->toHaveCount(25)
        ->and(array_values($searchResults))->toContain(
            "Line #{$lines->last()->getKey()} · Needle Correction · 2500.000 LYD",
        )
        ->and($field->getOptionLabel())->toBe(
            "Line #{$selected->getKey()} · Needle Correction · 2500.000 LYD",
        );
});

it('bounds the payroll assignment multi-select and redisplays exact assignment labels', function () {
    $this->seed(RolePermissionSeeder::class);

    $actor = User::factory()->create(['is_active' => true]);
    app(SystemRoleWriter::class)->assignRoles($actor, 'super_admin');

    $course = Course::factory()->create();
    $batch = Batch::factory()->for($course)->create(['code' => 'PAY-BATCH']);
    $users = User::factory()->count(2_000)->create();
    $users->take(30)->each(function (User $user, int $index): void {
        $user->update([
            'name' => 'Needle Instructor '.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
        ]);
    });
    $batch->instructors()->attach($users->mapWithKeys(
        fn (User $user): array => [$user->getKey() => ['assigned_hours' => 7]],
    )->all());

    $paidUser = $users->firstOrFail();
    $paidAssignmentId = (int) DB::table('batch_instructor')
        ->where('batch_id', $batch->getKey())
        ->where('user_id', $paidUser->getKey())
        ->value('id');
    PayrollLine::factory()->instructor()->finalized()->create([
        'user_id' => $paidUser->getKey(),
        'batch_instructor_id' => $paidAssignmentId,
        'frozen_hours' => 7,
    ]);

    $component = Livewire::actingAs($actor)->test(CreatePayrollRun::class);
    $field = $component->instance()->getSchema('form')?->getComponent('assignment_ids', withHidden: true);

    expect($field)->toBeInstanceOf(Select::class);

    /** @var Select $field */
    $searchResults = $field->getSearchResults('Needle Instructor');
    $selectedId = (int) array_key_first($searchResults);
    $field->state([$selectedId]);
    $selectedLabels = $field->getOptionLabels();

    expect($field->isMultiple())->toBeTrue()
        ->and($searchResults)->toHaveCount(25)
        ->and($searchResults)->not->toHaveKey($paidAssignmentId)
        ->and(array_values($searchResults))->toContain('Needle Instructor 01 · batch #PAY-BATCH · 7 hours')
        ->and(array_values($selectedLabels))->toContain($searchResults[$selectedId]);
});
