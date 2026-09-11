<?php

declare(strict_types=1);

use App\Domain\Enrollment\Filament\Resources\BatchResource\Pages\CreateBatch;
use App\Domain\Enrollment\Filament\Resources\BatchResource\Pages\ViewBatch;
use App\Domain\Enrollment\Filament\Resources\BatchResource\RelationManagers\InstructorsRelationManager;
use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Course;
use App\Domain\Finance\Filament\Pages\EnrollAndCollect;
use App\Domain\Finance\Models\Discount;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Domain\Staff\Models\StaffProfile;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('bounds the batch course picker and redisplays a retired course', function () {
    $this->seed(RolePermissionSeeder::class);

    $actor = User::factory()->create(['is_active' => true]);
    app(SystemRoleWriter::class)->assignRoles($actor, 'admin');

    Course::factory()->count(1_970)->create();
    Course::factory()->count(30)->sequence(
        fn ($sequence): array => [
            'code' => 'NEEDLE-'.str_pad((string) $sequence->index, 2, '0', STR_PAD_LEFT),
        ],
    )->create();
    $retired = Course::factory()->inactive()->create(['code' => 'RETIRED-001']);

    $component = Livewire::actingAs($actor)->test(CreateBatch::class);
    $field = $component->instance()->getSchema('form')?->getComponent('course_id');

    expect($field)->toBeInstanceOf(Select::class);

    /** @var Select $field */
    $searchResults = $field->getSearchResults('NEEDLE');
    $field->state($retired->getKey());

    expect($searchResults)->toHaveCount(25)
        ->and(array_values($searchResults))->toContain('NEEDLE-00')
        ->and($field->getOptionLabel())->toBe('RETIRED-001');
});

it('bounds the enrolment batch and discount pickers and resolves retired selections', function () {
    $this->seed(RolePermissionSeeder::class);

    $actor = User::factory()->create(['is_active' => true]);
    app(SystemRoleWriter::class)->assignRoles($actor, 'admin');

    $course = Course::factory()->create(['code' => 'COURSE-001']);
    Batch::factory()->for($course)->count(1_970)->create();
    Batch::factory()->for($course)->count(30)->sequence(
        fn ($sequence): array => ['code' => 'NEEDLE-BATCH-'.str_pad((string) $sequence->index, 2, '0', STR_PAD_LEFT)],
    )->create();
    $closedBatch = Batch::factory()->for($course)->completed()->create(['code' => 'CLOSED-001']);

    Discount::factory()->count(1_970)->create();
    Discount::factory()->count(30)->sequence(
        fn ($sequence): array => [
            'name' => 'Needle Discount '.str_pad((string) $sequence->index, 2, '0', STR_PAD_LEFT),
            'percentage' => '5.00',
        ],
    )->create();
    $retiredDiscount = Discount::factory()->inactive()->create([
        'name' => 'Retired Discount',
        'percentage' => '15.00',
    ]);

    $component = Livewire::actingAs($actor)->test(EnrollAndCollect::class);
    $batchField = $component->instance()->getSchema('form')?->getComponent('batch_id');
    $discountField = $component->instance()->getSchema('form')?->getComponent('discount_id');

    expect($batchField)->toBeInstanceOf(Select::class)
        ->and($discountField)->toBeInstanceOf(Select::class);

    /** @var Select $batchField */
    $batchResults = $batchField->getSearchResults('NEEDLE-BATCH');
    $batchField->state($closedBatch->getKey());

    /** @var Select $discountField */
    $discountResults = $discountField->getSearchResults('Needle Discount');
    $discountField->state($retiredDiscount->getKey());

    expect($batchResults)->toHaveCount(25)
        ->and(array_values($batchResults))->toContain('NEEDLE-BATCH-00 — COURSE-001')
        ->and($batchField->getOptionLabel())->toBe('CLOSED-001 — COURSE-001')
        ->and($discountResults)->toHaveCount(25)
        ->and(array_values($discountResults))->toContain('Needle Discount 00 (5.00%)')
        ->and($discountField->getOptionLabel())->toBe('Retired Discount (15.00%)');
});

it('bounds the eligible instructor picker and resolves a submitted eligible account', function () {
    $this->seed(RolePermissionSeeder::class);

    $actor = User::factory()->create(['is_active' => true]);
    app(SystemRoleWriter::class)->assignRoles($actor, 'admin');

    StaffProfile::factory()->count(1_970)->instructor()->create();
    User::factory()->count(30)->sequence(
        fn ($sequence): array => ['name' => 'Needle Instructor '.str_pad((string) $sequence->index, 2, '0', STR_PAD_LEFT)],
    )->create()->each(
        fn (User $user) => StaffProfile::factory()->for($user)->instructor()->create(),
    );
    $selected = User::query()->where('name', 'Needle Instructor 29')->sole();

    $batch = Batch::factory()->create();
    $component = Livewire::actingAs($actor)->test(InstructorsRelationManager::class, [
        'ownerRecord' => $batch,
        'pageClass' => ViewBatch::class,
    ])->mountTableAction('assign');
    $field = $component->instance()->getSchema('mountedActionSchema0')?->getComponent('user_id');

    expect($field)->toBeInstanceOf(Select::class);

    /** @var Select $field */
    $searchResults = $field->getSearchResults('Needle Instructor');
    $field->state($selected->getKey());

    expect($searchResults)->toHaveCount(25)
        ->and(array_values($searchResults))->toContain('Needle Instructor 00')
        ->and($field->getOptionLabel())->toBe('Needle Instructor 29');
});
