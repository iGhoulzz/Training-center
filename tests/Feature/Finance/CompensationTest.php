<?php

declare(strict_types=1);

use App\Domain\Finance\Actions\ChangeCompensationAction;
use App\Domain\Finance\Enums\CompensationType;
use App\Domain\Finance\Filament\Resources\StaffCompensationResource;
use App\Domain\Finance\Filament\Resources\StaffCompensationResource\Pages\CreateStaffCompensation;
use App\Domain\Finance\Filament\Resources\StaffCompensationResource\Pages\ListStaffCompensations;
use App\Domain\Finance\Models\StaffCompensation;
use App\Domain\Finance\Policies\StaffCompensationPolicy;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Domain\Staff\Models\StaffProfile;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Actions\CreateAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);

    $this->makeCompensationActor = function (string $role): User {
        $actor = User::factory()->create(['is_active' => true]);
        app(SystemRoleWriter::class)->assignRoles($actor, $role);

        return $actor->refresh();
    };
});

it('creates an initial rate and attributes it to the explicit actor', function () {
    $actor = ($this->makeCompensationActor)('super_admin');
    $signedInUser = ($this->makeCompensationActor)('super_admin');
    $employee = User::factory()->create(['name' => 'Salaried Employee']);

    $this->actingAs($signedInUser);

    $rate = app(ChangeCompensationAction::class)->execute(
        $actor,
        (int) $employee->getKey(),
        CompensationType::Salary,
        '2500.000',
        '2026-01-01',
    );

    $activity = Activity::query()
        ->where('subject_type', StaffCompensation::class)
        ->where('subject_id', $rate->getKey())
        ->where('event', 'created')
        ->firstOrFail();

    expect($rate->user_id)->toBe((int) $employee->getKey())
        ->and($rate->type)->toBe(CompensationType::Salary)
        ->and($rate->amount)->toBe('2500.000')
        ->and($rate->effective_from->toDateString())->toBe('2026-01-01')
        ->and($rate->effective_to)->toBeNull()
        ->and((int) $activity->causer_id)->toBe((int) $actor->getKey());
});

it('closes the previous rate and inserts a contiguous replacement without overwriting history', function () {
    $actor = ($this->makeCompensationActor)('super_admin');
    $employee = User::factory()->create();
    $action = app(ChangeCompensationAction::class);

    $previous = $action->execute(
        $actor,
        (int) $employee->getKey(),
        CompensationType::Salary,
        '2500.000',
        '2026-01-01',
    );

    $replacement = $action->execute(
        $actor,
        (int) $employee->getKey(),
        CompensationType::Salary,
        '3000.000',
        '2026-04-01',
    );

    expect(StaffCompensation::query()->count())->toBe(2)
        ->and($previous->fresh()?->amount)->toBe('2500.000')
        ->and($previous->fresh()?->effective_to?->toDateString())->toBe('2026-03-31')
        ->and($replacement->amount)->toBe('3000.000')
        ->and($replacement->effective_from->toDateString())->toBe('2026-04-01')
        ->and($replacement->effective_to)->toBeNull();

    $events = Activity::query()
        ->where('subject_type', StaffCompensation::class)
        ->whereIn('subject_id', [$previous->getKey(), $replacement->getKey()])
        ->orderBy('id')
        ->get();

    expect($events->pluck('event')->all())->toBe(['created', 'updated', 'created'])
        ->and($events->pluck('causer_id')->map(fn ($id): int => (int) $id)->unique()->all())
        ->toBe([(int) $actor->getKey()]);
});

it('allows salary and hourly rates for one employee at the same time', function () {
    $actor = ($this->makeCompensationActor)('super_admin');
    $employee = User::factory()->create();
    $action = app(ChangeCompensationAction::class);

    $action->execute($actor, (int) $employee->getKey(), CompensationType::Salary, '2500.000', '2026-01-01');
    $action->execute($actor, (int) $employee->getKey(), CompensationType::Hourly, '35.000', '2026-01-01');

    expect(StaffCompensation::query()->where('user_id', $employee->getKey())->count())->toBe(2);
});

it('refuses a replacement that would overlap the existing timeline without changing it', function () {
    $actor = ($this->makeCompensationActor)('super_admin');
    $employee = User::factory()->create();
    $existing = StaffCompensation::factory()->create([
        'user_id' => $employee->getKey(),
        'type' => CompensationType::Salary,
        'amount' => '2500.000',
        'effective_from' => '2026-01-01',
        'effective_to' => null,
    ]);

    expect(fn () => app(ChangeCompensationAction::class)->execute(
        $actor,
        (int) $employee->getKey(),
        CompensationType::Salary,
        '3000.000',
        '2026-01-01',
    ))->toThrow(ValidationException::class);

    expect(StaffCompensation::query()->count())->toBe(1)
        ->and($existing->fresh()?->amount)->toBe('2500.000')
        ->and($existing->fresh()?->effective_to)->toBeNull();
});

it('validates decimal precision and dates inside the Action', function (string $amount, string $date) {
    $actor = ($this->makeCompensationActor)('super_admin');
    $employee = User::factory()->create();

    expect(fn () => app(ChangeCompensationAction::class)->execute(
        $actor,
        (int) $employee->getKey(),
        CompensationType::Salary,
        $amount,
        $date,
    ))->toThrow(ValidationException::class);

    expect(StaffCompensation::query()->count())->toBe(0);
})->with([
    'float-prone fourth decimal' => ['10.0001', '2026-01-01'],
    'zero amount' => ['0.000', '2026-01-01'],
    'invalid date' => ['10.000', '2026-02-30'],
]);

it('authorizes the Action before loading or validating its target', function () {
    $admin = ($this->makeCompensationActor)('admin');

    expect(fn () => app(ChangeCompensationAction::class)->execute(
        $admin,
        PHP_INT_MAX,
        CompensationType::Salary,
        'not-a-rate',
        'not-a-date',
    ))->toThrow(AuthorizationException::class);
});

it('lets admins read compensation but only super admins create it through the Action-backed page', function () {
    $employee = User::factory()->create(['name' => 'Visible Employee']);
    StaffProfile::factory()->create(['user_id' => $employee->getKey()]);
    $rate = StaffCompensation::factory()->create(['user_id' => $employee->getKey()]);
    $admin = ($this->makeCompensationActor)('admin');
    $staff = ($this->makeCompensationActor)('staff');
    $superAdmin = ($this->makeCompensationActor)('super_admin');

    $this->actingAs($staff)->get('/admin/staff-compensations')->assertForbidden();
    $this->actingAs($staff)->get("/admin/staff-compensations/{$rate->getKey()}")->assertForbidden();
    $this->actingAs($staff)->get('/admin/staff-compensations/create')->assertForbidden();

    $this->actingAs($admin)->get('/admin/staff-compensations')->assertSuccessful();
    $this->actingAs($admin)->get("/admin/staff-compensations/{$rate->getKey()}")->assertSuccessful();
    $this->actingAs($admin)->get('/admin/staff-compensations/create')->assertForbidden();

    $adminList = Livewire::actingAs($admin)->test(ListStaffCompensations::class);
    $adminList->assertActionHidden('create');

    $createAction = Livewire::actingAs($superAdmin)
        ->test(ListStaffCompensations::class)
        ->instance()
        ->getAction('create');

    expect($createAction)->not->toBeInstanceOf(CreateAction::class);

    Livewire::actingAs($superAdmin)
        ->test(CreateStaffCompensation::class)
        ->fillForm([
            'user_id' => $employee->getKey(),
            'type' => CompensationType::Hourly->value,
            'amount' => '42.125',
            'effective_from' => '2026-09-01',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(StaffCompensation::query()->where('user_id', $employee->getKey())->count())->toBe(2)
        ->and(StaffCompensation::query()->latest('id')->firstOrFail()->amount)->toBe('42.125');
});

it('keeps every non-create policy write closed even if a matching permission is granted', function (string $method, string $permission) {
    Permission::findOrCreate($permission);
    $superAdmin = ($this->makeCompensationActor)('super_admin');
    $superAdmin->givePermissionTo($permission);
    $rate = StaffCompensation::factory()->create();
    $arguments = in_array($method, ['deleteAny', 'restoreAny', 'forceDeleteAny', 'reorder'], true)
        ? [$superAdmin]
        : [$superAdmin, $rate];

    expect(app(StaffCompensationPolicy::class)->{$method}(...$arguments))->toBeFalse();
})->with([
    'update' => ['update', 'update_staff_compensation'],
    'delete' => ['delete', 'delete_staff_compensation'],
    'bulk delete' => ['deleteAny', 'delete_any_staff_compensation'],
    'restore' => ['restore', 'restore_staff_compensation'],
    'bulk restore' => ['restoreAny', 'restore_any_staff_compensation'],
    'force delete' => ['forceDelete', 'force_delete_staff_compensation'],
    'bulk force delete' => ['forceDeleteAny', 'force_delete_any_staff_compensation'],
    'replicate' => ['replicate', 'replicate_staff_compensation'],
    'reorder' => ['reorder', 'reorder_staff_compensation'],
]);

it('confines compensation writes in application code to ChangeCompensationAction', function () {
    $allowedWriter = strtolower(str_replace('\\', '/', app_path('Domain/Finance/Actions/ChangeCompensationAction.php')));
    $violations = [];
    $writePatterns = [
        '/\bStaffCompensation::(?:(?:query|newQuery)\(\)->)?(?:create|createQuietly|forceCreate|forceCreateQuietly|insert|upsert|updateOrCreate)\s*\(/',
        '/(?:update|fill|forceFill)\s*\(\s*\[(?:(?!\]\s*\)).)*[\'\"](?:amount|effective_from|effective_to)[\'\"]\s*=>/s',
        '/->\s*(?:delete|forceDelete)\s*\(\s*\)/',
    ];

    foreach (File::allFiles(app_path()) as $file) {
        $path = strtolower(str_replace('\\', '/', $file->getPathname()));

        if ($file->getExtension() !== 'php' || $path === $allowedWriter) {
            continue;
        }

        $source = appSourceWithoutComments($file->getPathname());

        if (preg_match('/\bStaffCompensation\b|staff_compensation/', $source) !== 1) {
            continue;
        }

        foreach ($writePatterns as $pattern) {
            if (preg_match($pattern, $source) === 1) {
                $violations[] = str_replace(app_path().DIRECTORY_SEPARATOR, '', $file->getPathname());
                break;
            }
        }
    }

    expect($violations)->toBe([])
        ->and(StaffCompensationResource::getPages())->not->toHaveKey('edit');
});
