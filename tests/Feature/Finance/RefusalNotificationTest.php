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

    /*
     * EVERY LINE THE BODY PASSES THROUGH IS A SENTINEL, AND THAT IS THE CHANGE.
     *
     * The title was already asserted through a sentinel; the body was asserted as
     * its English copy, "Attempted 1500.000 LYD; outstanding 1000.000 LYD." That
     * is what the shipped translation happens to render, so the assertion passed
     * for a body built any way at all — including one hardcoded in the page, which
     * is what non-negotiable 5 forbids and what phase 4's Arabic pass would then
     * leave silently in English.
     *
     * THE MONEY LINE IS NOT OPTIONAL HERE, FOR A REASON WORTH KNOWING:
     * Lang::addLines marks a whole GROUP as loaded, so adding one `collect.*` key
     * stops `lang/en/collect.php` being read at all, and every other key in that
     * group degrades to its own name. Adding only the detail line produced
     * "attempted=collect.amount_lyd" — the test would have been asserting against
     * a half-broken translation table rather than the page's behaviour.
     *
     * Sentinels for both also make the assertion stronger than the English copy
     * was: it now fails unless the body is routed through the detail key, each
     * amount through the money key, and the two land in the right slots. A body
     * that swapped them reads plausibly in English and is wrong.
     */
    Lang::addLines([
        'payments.exceeds_outstanding' => 'SENTINEL-EXCEEDS-OUTSTANDING',
        'collect.payment_exceeds_outstanding_detail' => 'SENTINEL-DETAIL attempted=:attempted outstanding=:outstanding',
        'collect.amount_lyd' => 'SENTINEL-MONEY(:amount)',
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
            ->body('SENTINEL-DETAIL attempted=SENTINEL-MONEY(1500.000) outstanding=SENTINEL-MONEY(1000.000)')
            ->danger(),
    );
});
