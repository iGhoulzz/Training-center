<?php

declare(strict_types=1);

use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Course;
use App\Domain\Finance\Enums\CompensationType;
use App\Domain\Finance\Enums\PayrollRunType;
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

/**
 * @param  ArrayObject<int, array{sql: string, bindings: array<int, mixed>, level: int}>  $statements
 * @param  list<string>  $projectedColumns
 */
function expectBoundedPayrollOptionQuery(ArrayObject $statements, string $table, array $projectedColumns): void
{
    $queries = collect($statements)
        ->filter(fn (array $statement): bool => str_starts_with($statement['sql'], 'select ')
            && str_contains($statement['sql'], " from `{$table}`"))
        ->values();

    expect($queries)->toHaveCount(1);

    $sql = $queries->firstOrFail()['sql'];
    $projection = explode(' from ', $sql, 2)[0];

    expect($sql)->toMatch('/\blimit 25\b/')
        ->and($projection)->not->toContain('*');

    foreach ($projectedColumns as $column) {
        expect($projection)->toContain("`{$column}`");
    }
}

it('submits multiple instructor assignments through the real payroll create form', function () {
    $this->seed(RolePermissionSeeder::class);

    $actor = User::factory()->create(['is_active' => true]);
    app(SystemRoleWriter::class)->assignRoles($actor, 'super_admin');

    $batch = Batch::factory()
        ->for(Course::factory())
        ->create(['code' => 'FORM-BATCH']);
    $instructors = User::factory()->count(2)->create();

    foreach ($instructors as $instructor) {
        StaffCompensation::factory()->create([
            'user_id' => $instructor->getKey(),
            'type' => CompensationType::Hourly,
            'amount' => '12.500',
            'effective_from' => '2026-01-01',
            'effective_to' => null,
        ]);
    }

    $batch->instructors()->attach([
        $instructors[0]->getKey() => ['assigned_hours' => 3],
        $instructors[1]->getKey() => ['assigned_hours' => 5],
    ]);
    $assignmentIds = DB::table('batch_instructor')
        ->where('batch_id', $batch->getKey())
        ->orderBy('id')
        ->pluck('id')
        ->map(fn (int $id): int => $id)
        ->all();

    $component = Livewire::actingAs($actor)
        ->test(CreatePayrollRun::class)
        ->fillForm([
            'type' => PayrollRunType::InstructorBatch->value,
            'assignment_ids' => $assignmentIds,
            'notes' => 'Submitted through Filament.',
        ]);

    $dehydratedState = $component->instance()->getSchema('form')?->getState();

    expect($dehydratedState['assignment_ids'] ?? null)->toBe($assignmentIds)
        ->and($dehydratedState['assignment_ids'])->each->toBeInt();

    $component
        ->call('create')
        ->assertHasNoFormErrors();

    $run = PayrollRun::query()->sole();
    $lines = $run->lines()->orderBy('batch_instructor_id')->get();

    expect($run->type)->toBe(PayrollRunType::InstructorBatch)
        ->and($lines)->toHaveCount(2)
        ->and($lines->pluck('batch_instructor_id')->all())->toBe($assignmentIds)
        ->and($lines->pluck('frozen_hours')->all())->toBe([3, 5]);
});

it('rejects an ineligible compensation owner submitted through the create form', function (bool $isActive, bool $hasProfile) {
    $this->seed(RolePermissionSeeder::class);

    $actor = User::factory()->create(['is_active' => true]);
    app(SystemRoleWriter::class)->assignRoles($actor, 'super_admin');

    $employee = User::factory()->create(['is_active' => $isActive]);

    if ($hasProfile) {
        StaffProfile::factory()->for($employee)->create();
    }

    Livewire::actingAs($actor)
        ->test(CreateStaffCompensation::class)
        ->fillForm([
            'user_id' => $employee->getKey(),
            'type' => CompensationType::Hourly->value,
            'amount' => '12.500',
            'effective_from' => '2026-09-01',
        ])
        ->call('create')
        ->assertHasFormErrors(['user_id']);

    expect(StaffCompensation::query()->count())->toBe(0);
})->with([
    'inactive account with profile' => [false, true],
    'active account without profile' => [true, false],
]);

it('bounds staff owner pickers and resolves values within each picker eligibility domain', function () {
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
    $eligibleCompensationOwner = $profiles[29]->user;

    $profilePage = Livewire::actingAs($actor)->test(CreateStaffProfile::class);
    $profileField = $profilePage->instance()->getSchema('form')?->getComponent('user_id');
    $compensationPage = Livewire::actingAs($actor)->test(CreateStaffCompensation::class);
    $compensationField = $compensationPage->instance()->getSchema('form')?->getComponent('user_id');

    expect($profileField)->toBeInstanceOf(Select::class)
        ->and($compensationField)->toBeInstanceOf(Select::class);

    /** @var Select $profileField */
    $profileInitialStatements = captureStatements();
    $profileInitialOptions = $profileField->getOptions();
    expectBoundedPayrollOptionQuery($profileInitialStatements, 'users', ['id', 'name']);

    $profileSearchStatements = captureStatements();
    $profileResults = $profileField->getSearchResults('Needle Employee');
    expectBoundedPayrollOptionQuery($profileSearchStatements, 'users', ['id', 'name']);
    $profileField->state($departed->getKey());

    /** @var Select $compensationField */
    $compensationInitialStatements = captureStatements();
    $compensationInitialOptions = $compensationField->getOptions();
    expectBoundedPayrollOptionQuery($compensationInitialStatements, 'users', ['id', 'name']);

    $compensationSearchStatements = captureStatements();
    $compensationResults = $compensationField->getSearchResults('Needle Employee');
    expectBoundedPayrollOptionQuery($compensationSearchStatements, 'users', ['id', 'name']);
    $compensationField->state($eligibleCompensationOwner->getKey());

    expect($profileInitialOptions)->toHaveCount(25)
        ->and($profileResults)->toHaveCount(25)
        ->and(array_values($profileResults))->toContain('Needle Employee 00')
        ->and($profileField->getOptionLabel())->toBe('Departed Employee')
        ->and($compensationInitialOptions)->toHaveCount(25)
        ->and($compensationResults)->toHaveCount(25)
        ->and(array_values($compensationResults))->toContain('Needle Employee 00')
        ->and($compensationField->getOptionLabel())->toBe('Needle Employee 29');
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
    $searchStatements = captureStatements();
    $searchResults = $field->getSearchResults('Needle Correction');
    expectBoundedPayrollOptionQuery($searchStatements, 'payroll_lines', ['id', 'user_id', 'computed_amount']);
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
    $searchStatements = captureStatements();
    $searchResults = $field->getSearchResults('Needle Instructor');
    expectBoundedPayrollOptionQuery($searchStatements, 'batch_instructor', [
        'id',
        'batch_id',
        'batch_code',
        'user_id',
        'user_name',
        'assigned_hours',
    ]);
    $selectedId = (int) array_key_first($searchResults);
    $field->state([$selectedId]);
    $selectedLabels = $field->getOptionLabels();

    expect($field->isMultiple())->toBeTrue()
        ->and($searchResults)->toHaveCount(25)
        ->and($searchResults)->not->toHaveKey($paidAssignmentId)
        ->and(array_values($searchResults))->toContain('Needle Instructor 01 · batch #PAY-BATCH · 7 hours')
        ->and(array_values($selectedLabels))->toContain($searchResults[$selectedId]);
});
