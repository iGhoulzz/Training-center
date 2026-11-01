<?php

declare(strict_types=1);

use App\Domain\Finance\Filament\Resources\DiscountResource\Pages\CreateDiscount;
use App\Domain\Publications\Filament\Resources\ArticleResource\Pages\CreateArticle;
use App\Domain\Publications\Filament\Resources\ArticleResource\Pages\EditArticle;
use App\Domain\Publications\Models\Article;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Schemas\Components\Section;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

/**
 * The Article and Discount forms sit in section cards like every other admin form (P4-T42).
 *
 * Article's fields used to lie directly on the page, and Discount's heading was small grey text
 * above a bordered box rather than a card heading. The check is on what the form is made of: every
 * top-level component is a Section, and the page draws the cards and their headings.
 */
uses(RefreshDatabase::class);

/**
 * @param  list<string>  $permissions
 */
function formActorHolding(array $permissions): User
{
    $role = Role::query()->create(['name' => 'author_'.uniqid(), 'guard_name' => 'web']);
    $writer = app(SystemRoleWriter::class);
    $writer->syncRolePermissions($role, ['access_admin_panel', ...$permissions]);

    $user = User::factory()->create(['is_active' => true]);
    $writer->assignRoles($user, $role->name);

    return $user;
}

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

it('draws the article form as section cards, on create and on edit', function (string $page) {
    $actor = formActorHolding(['view_any_article', 'view_article', 'create_article', 'update_article']);

    $mounted = match ($page) {
        'create' => Livewire::actingAs($actor)->test(CreateArticle::class),
        'edit' => Livewire::actingAs($actor)->test(EditArticle::class, ['record' => Article::factory()->create()->getKey()]),
    };

    $components = $mounted->assertOk()->instance()->form->getComponents();

    // Not vacuous: three cards, and every top-level component is one of them.
    expect($components)->toHaveCount(3)
        ->and(collect($components)->every(fn ($component): bool => $component instanceof Section))->toBeTrue();

    $mounted->assertSee(__('publications.article_details'))
        ->assertSee(__('publications.article_descriptions'))
        ->assertSee(__('publications.article_document'))
        ->assertSeeHtml('fi-section');
})->with(['create', 'edit']);

it('draws the discount form as a section card with a card heading', function () {
    $mounted = Livewire::actingAs(formActorHolding(['view_any_discount', 'manage_pricing']))
        ->test(CreateDiscount::class)
        ->assertOk();

    $components = $mounted->instance()->form->getComponents();

    expect($components)->toHaveCount(1)
        ->and($components[0])->toBeInstanceOf(Section::class);

    $mounted->assertSee(__('pricing.discount_definition'))
        ->assertSee(__('pricing.immutable_hint'))
        ->assertSeeHtml('fi-section-header-heading');
});
