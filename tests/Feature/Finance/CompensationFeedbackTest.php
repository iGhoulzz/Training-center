<?php

declare(strict_types=1);

use App\Domain\Finance\Actions\ChangeCompensationAction;
use App\Domain\Finance\Enums\CompensationType;
use App\Domain\Finance\Filament\Resources\StaffCompensationResource\Pages\CreateStaffCompensation;
use App\Domain\Finance\Models\StaffCompensation;
use App\Domain\Staff\Models\StaffProfile;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

/*
 * The refusal text is typed out rather than read back from lang/en/payroll.php:
 * the test must fail if the page shows a different sentence from the one the
 * service refused with, and reading the same key on both sides would not.
 */
const COMPENSATION_OVERLAP_MESSAGE = 'This date overlaps the employee’s existing compensation history for that type.';

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);

    /*
     * Exactly the two permissions this page needs, and not the super admin role,
     * which would pass whatever ability the page or Action checked.
     */
    $this->compensationActor = User::factory()->create(['is_active' => true]);
    $this->compensationActor->givePermissionTo(
        Permission::findByName('view_any_staff_compensation'),
        Permission::findByName('create_staff_compensation'),
    );

    $this->employee = User::factory()->create(['name' => 'Feedback Employee']);
    StaffProfile::factory()->create(['user_id' => $this->employee->getKey()]);
});

it('shows the refused effective date under the field for an overlapping and a backdated date', function (string $history, string $effectiveFrom) {
    // Hand-built timelines. 'closed' has no open row, so the date lands inside
    // a closed period; 'open' has an open row, so the date predates its start.
    if ($history === 'closed') {
        StaffCompensation::factory()->create([
            'user_id' => $this->employee->getKey(),
            'type' => CompensationType::Salary,
            'amount' => '2500.000',
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-03-31',
        ]);
    } else {
        StaffCompensation::factory()->create([
            'user_id' => $this->employee->getKey(),
            'type' => CompensationType::Salary,
            'amount' => '2500.000',
            'effective_from' => '2026-04-01',
            'effective_to' => null,
        ]);
    }

    $page = Livewire::actingAs($this->compensationActor)
        ->test(CreateStaffCompensation::class)
        ->fillForm([
            'user_id' => $this->employee->getKey(),
            'type' => CompensationType::Salary->value,
            'amount' => '2750.125',
            'effective_from' => $effectiveFrom,
        ])
        ->call('create')
        ->assertHasFormErrors(['effective_from']);

    expect($page->instance()->getErrorBag()->get('data.effective_from'))->toBe([COMPENSATION_OVERLAP_MESSAGE]);

    $page->assertSee(COMPENSATION_OVERLAP_MESSAGE);

    expect(StaffCompensation::query()->where('user_id', $this->employee->getKey())->count())->toBe(1)
        ->and(StaffCompensation::query()->where('amount', '2750.125')->exists())->toBeFalse();
})->with([
    'a date inside a closed period' => ['closed', '2026-02-01'],
    'a date before the open rate starts' => ['open', '2026-02-01'],
]);

it('leaves the history untouched when the refused date would have closed the open rate', function () {
    $open = StaffCompensation::factory()->create([
        'user_id' => $this->employee->getKey(),
        'type' => CompensationType::Salary,
        'amount' => '2500.000',
        'effective_from' => '2026-04-01',
        'effective_to' => null,
    ]);

    Livewire::actingAs($this->compensationActor)
        ->test(CreateStaffCompensation::class)
        ->fillForm([
            'user_id' => $this->employee->getKey(),
            'type' => CompensationType::Salary->value,
            'amount' => '2750.125',
            'effective_from' => '2026-04-01',
        ])
        ->call('create')
        ->assertHasFormErrors(['effective_from']);

    expect($open->fresh()?->effective_to)->toBeNull();
});

it('still creates the rate when the date is accepted', function () {
    Livewire::actingAs($this->compensationActor)
        ->test(CreateStaffCompensation::class)
        ->fillForm([
            'user_id' => $this->employee->getKey(),
            'type' => CompensationType::Salary->value,
            'amount' => '2750.125',
            'effective_from' => '2026-09-01',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(StaffCompensation::query()->where('user_id', $this->employee->getKey())->sole()->amount)->toBe('2750.125');
});

it('turns a refusal that no form field owns into a persistent danger notification and writes nothing', function () {
    /*
     * No real refusal today names a key outside the form, so the Action is
     * replaced by one that does. The binding is a plain object: the Action is
     * final, and the page only calls execute() on whatever the container gives.
     */
    app()->instance(
        ChangeCompensationAction::class,
        new class
        {
            public function execute(): never
            {
                throw ValidationException::withMessages(['unowned_key' => 'SENTINEL-UNOWNED-REFUSAL']);
            }
        },
    );

    Livewire::actingAs($this->compensationActor)
        ->test(CreateStaffCompensation::class)
        ->fillForm([
            'user_id' => $this->employee->getKey(),
            'type' => CompensationType::Salary->value,
            'amount' => '2750.125',
            'effective_from' => '2026-09-01',
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified(FilamentNotification::make()->title('SENTINEL-UNOWNED-REFUSAL')->danger()->persistent());

    expect(StaffCompensation::query()->count())->toBe(0);
});
