<?php

declare(strict_types=1);

use App\Domain\Publications\Filament\Resources\ArticleResource\Pages\CreateArticle;
use App\Domain\Publications\Filament\Resources\ArticleResource\Pages\EditArticle;
use App\Domain\Publications\Models\Article;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

/**
 * What the article form says when the upload is wrong (P3-6).
 *
 * Filament builds a validation message's attribute from the field's label with
 * its first letter lower-cased, so "PDF file" used to read "The pDF file field
 * must be a file of type: application/pdf." The field now names itself from a
 * lang key and states the PDF rule as a sentence of its own.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    // Exactly the grants each page needs; neither actor is a super admin.
    $this->actorWith = function (string ...$permissions): User {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(...$permissions);

        return $user->fresh();
    };

    $this->fill = fn (array $overrides = []): array => [
        'title_en' => 'Welding Safety Handbook',
        'description_en' => 'Protective equipment and ventilation for the workshop.',
        'topic' => 'Safety',
        'authors' => 'Hana Belaid',
        'issued_on' => '2025-11-03',
        ...$overrides,
    ];

    /** The messages the form shows against the PDF field after a failed save. */
    $this->pdfErrors = fn ($page): array => $page->errors()->get('data.pdf_file');
});

it('says the file must be a PDF when something else is uploaded on create', function () {
    $actor = ($this->actorWith)('view_any_article', 'view_article', 'create_article');

    $page = Livewire::actingAs($actor)
        ->test(CreateArticle::class)
        ->fillForm(($this->fill)(['pdf_file' => livewirePngUpload('cover.png')]))
        ->call('create')
        ->assertHasFormErrors(['pdf_file']);

    // Hand-written expectation, so a change to the lang key shows up here.
    expect(($this->pdfErrors)($page))->toBe(['The file must be a PDF.'])
        ->and(Article::count())->toBe(0);
});

it('says the file must be a PDF when something else replaces the file on edit', function () {
    $actor = ($this->actorWith)('view_any_article', 'view_article', 'update_article');
    $article = Article::factory()->create();

    $page = Livewire::actingAs($actor)
        ->test(EditArticle::class, ['record' => $article->getKey()])
        ->fillForm(['pdf_file' => livewirePngUpload('cover.png')])
        ->call('save')
        ->assertHasFormErrors(['pdf_file']);

    expect(($this->pdfErrors)($page))->toBe(['The file must be a PDF.']);
});

it('names the field "PDF file" in its other messages, never "pDF"', function () {
    $actor = ($this->actorWith)('view_any_article', 'view_article', 'create_article');

    $page = Livewire::actingAs($actor)
        ->test(CreateArticle::class)
        ->fillForm(($this->fill)(['pdf_file' => null]))
        ->call('create')
        ->assertHasFormErrors(['pdf_file' => 'required']);

    // The required rule has no message of its own, so it shows the attribute.
    expect(($this->pdfErrors)($page))->toBe(['The PDF file field is required.']);
});

it('refuses the create page to an actor who holds one permission fewer', function () {
    // The control for the tests above: they prove the message, this proves the
    // actor they use is entitled to reach the form at all.
    $almost = ($this->actorWith)('view_any_article', 'view_article');

    Livewire::actingAs($almost)->test(CreateArticle::class)->assertForbidden();
});
