<?php

declare(strict_types=1);

use App\Domain\Enrollment\Models\Student;
use App\Domain\Finance\Filament\Pages\Reports\RevenueReportPage;
use App\Domain\Finance\Filament\Pages\Reports\StudentPaymentHistoryPage;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/** @param ArrayObject<int, array{sql: string, bindings: array<int, mixed>, level: int}> $statements */
function expectBoundedStudentOptionQuery(ArrayObject $statements): void
{
    $queries = collect($statements)
        ->filter(fn (array $statement): bool => str_starts_with($statement['sql'], 'select ')
            && str_contains($statement['sql'], ' from `students`'))
        ->values();

    expect($queries)->toHaveCount(1);

    $sql = $queries->firstOrFail()['sql'];
    $projection = explode(' from ', $sql, 2)[0];

    expect($sql)->toMatch('/\blimit 25\b/')
        ->and($projection)->not->toContain('*')
        ->and($projection)->toContain('`id`', '`student_code`', '`first_name`', '`last_name`');
}

it('rejects an end date before the start date through apply filters', function () {
    $this->seed(RolePermissionSeeder::class);

    $actor = User::factory()->create(['is_active' => true]);
    app(SystemRoleWriter::class)->assignRoles($actor, 'admin');

    $component = Livewire::actingAs($actor)->test(RevenueReportPage::class);
    $originalAppliedFilters = $component->get('appliedFilters');

    $component
        ->fillForm([
            'from' => '2026-09-10',
            'to' => '2026-09-09',
        ])
        ->call('applyFilters')
        ->assertHasFormErrors(['to'])
        ->assertSet('appliedFilters', $originalAppliedFilters);
});

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
    $searchStatements = captureStatements();
    $searchResults = $field->getSearchResults('Needle');
    expectBoundedStudentOptionQuery($searchStatements);
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
