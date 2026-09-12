<?php

declare(strict_types=1);

use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Domain\Staff\Filament\Resources\ActivityResource\Pages\ListActivities;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/** @param ArrayObject<int, array{sql: string, bindings: array<int, mixed>, level: int}> $statements */
function expectBoundedActorOptionQuery(ArrayObject $statements): void
{
    $queries = collect($statements)
        ->filter(fn (array $statement): bool => str_starts_with($statement['sql'], 'select ')
            && str_contains($statement['sql'], ' from `users`'))
        ->values();

    expect($queries)->toHaveCount(1);

    $sql = $queries->firstOrFail()['sql'];
    $projection = explode(' from ', $sql, 2)[0];

    expect($sql)->toMatch('/\blimit 25\b/')
        ->and($projection)->not->toContain('*')
        ->and($projection)->toContain('`id`', '`name`');
}

it('bounds the activity actor filter and redisplays a deleted actor', function () {
    $this->seed(RolePermissionSeeder::class);

    $actor = User::factory()->create(['is_active' => true]);
    app(SystemRoleWriter::class)->assignRoles($actor, 'admin');

    User::factory()->count(1_970)->create();
    User::factory()->count(30)->sequence(
        fn ($sequence): array => ['name' => 'Needle Actor '.str_pad((string) $sequence->index, 2, '0', STR_PAD_LEFT)],
    )->create();
    $historical = User::factory()->create(['name' => 'Historical Actor']);
    $historical->delete();

    $component = Livewire::actingAs($actor)->test(ListActivities::class);
    $field = $component->instance()->getTable()->getFilter('causer_id')?->getSchema()->getComponent('value');

    expect($field)->toBeInstanceOf(Select::class);

    /** @var Select $field */
    $initialStatements = captureStatements();
    $initialOptions = $field->getOptions();
    expectBoundedActorOptionQuery($initialStatements);

    $searchStatements = captureStatements();
    $searchResults = $field->getSearchResults('Needle Actor');
    expectBoundedActorOptionQuery($searchStatements);
    $field->state($historical->getKey());

    expect($initialOptions)->toHaveCount(25)
        ->and($searchResults)->toHaveCount(25)
        ->and(array_values($searchResults))->toContain('Needle Actor 00')
        ->and($field->getOptionLabel())->toBe('Historical Actor');
});
