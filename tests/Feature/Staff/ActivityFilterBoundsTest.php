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
    $searchResults = $field->getSearchResults('Needle Actor');
    $field->state($historical->getKey());

    expect($searchResults)->toHaveCount(25)
        ->and(array_values($searchResults))->toContain('Needle Actor 00')
        ->and($field->getOptionLabel())->toBe('Historical Actor');
});
