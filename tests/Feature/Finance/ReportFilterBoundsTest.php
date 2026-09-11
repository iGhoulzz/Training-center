<?php

declare(strict_types=1);

use App\Domain\Enrollment\Models\Student;
use App\Domain\Finance\Filament\Pages\Reports\StudentPaymentHistoryPage;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('bounds the report student picker and redisplays a historical selection', function () {
    $this->seed(RolePermissionSeeder::class);

    $actor = User::factory()->create(['is_active' => true]);
    app(SystemRoleWriter::class)->assignRoles($actor, 'admin');

    Student::factory()->count(1_970)->create();
    Student::factory()->count(30)->sequence(
        fn ($sequence): array => [
            'student_code' => 'NEEDLE-'.str_pad((string) $sequence->index, 2, '0', STR_PAD_LEFT),
            'first_name' => 'Needle',
            'last_name' => str_pad((string) $sequence->index, 2, '0', STR_PAD_LEFT),
        ],
    )->create();
    $historical = Student::factory()->create([
        'student_code' => 'HIST-001',
        'first_name' => 'Historical',
        'last_name' => 'Student',
    ]);
    $historical->delete();

    $component = Livewire::actingAs($actor)->test(StudentPaymentHistoryPage::class);
    $field = $component->instance()->getSchema('form')?->getComponent('student_id');

    expect($field)->toBeInstanceOf(Select::class);

    /** @var Select $field */
    $searchResults = $field->getSearchResults('Needle');
    $field->state($historical->getKey());

    expect($searchResults)->toHaveCount(25)
        ->and(array_values($searchResults))->toContain('NEEDLE-00 — Needle 00')
        ->and($field->getOptionLabel())->toBe('HIST-001 — Historical Student');

    $component
        ->fillForm(['student_id' => $historical->getKey()])
        ->call('applyFilters')
        ->assertHasNoFormErrors()
        ->assertSet('appliedFilters.student_id', $historical->getKey());
});
