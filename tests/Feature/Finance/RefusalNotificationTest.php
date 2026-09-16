<?php

declare(strict_types=1);

use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Student;
use App\Domain\Finance\Enums\TenderMethod;
use App\Domain\Finance\Filament\Pages\EnrollAndCollect;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Lang;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders the attempted and outstanding amounts in the overpayment refusal notification', function () {
    /*
     * MUTATION CAUGHT: remove the refusal notification's body, or replace
     * either exception-carried amount. The complete notification comparison
     * below then fails even though confirm() has already sent another toast.
     */
    $this->seed(RolePermissionSeeder::class);

    $admin = User::factory()->create(['is_active' => true]);
    app(SystemRoleWriter::class)->assignRoles($admin, 'admin');

    Lang::addLines([
        'payments.exceeds_outstanding' => 'SENTINEL-EXCEEDS-OUTSTANDING',
    ], app()->getLocale());

    $batch = Batch::factory()->create(['price' => '1000.000']);
    $student = Student::factory()->create();

    $component = Livewire::actingAs($admin->refresh())
        ->test(EnrollAndCollect::class)
        ->fillForm([
            'student_id' => $student->getKey(),
            'batch_id' => $batch->getKey(),
        ])
        ->call('confirm');

    $component
        ->fillForm([
            'amount' => '1500.000',
            'tenders' => [
                [
                    'method' => TenderMethod::Cash->value,
                    'amount' => '1500.000',
                    'external_reference' => null,
                ],
            ],
        ], 'collectForm')
        ->call('finalize');

    FilamentNotification::assertNotified(
        FilamentNotification::make()
            ->title('SENTINEL-EXCEEDS-OUTSTANDING')
            ->body('Attempted 1500.000 LYD; outstanding 1000.000 LYD.')
            ->danger(),
    );
});
