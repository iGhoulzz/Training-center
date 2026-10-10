<?php

declare(strict_types=1);

use App\Domain\Publications\Filament\Resources\ArticleResource\Pages\CreateArticle;
use App\Domain\Publications\Filament\Resources\ArticleResource\Pages\EditArticle;
use App\Domain\Publications\Filament\Resources\ArticleResource\Pages\ListArticles;
use App\Domain\Publications\Filament\Resources\ArticleResource\Pages\ViewArticle;
use App\Domain\Publications\Models\Article;
use App\Domain\Publications\Policies\ArticlePolicy;
use App\Domain\Staff\Actions\SystemRoleWriter;
use App\Domain\Staff\Models\PendingFileDeletion;
use App\Domain\Staff\Services\FileLifecycleService;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;

/**
 * The staff side of the library as it is actually reachable: over HTTP and
 * through the real Livewire components.
 *
 * "A correct policy nobody consults denies nothing" (CourseResourceTest). So every
 * permission claim here is driven through the page or the record action, with an
 * actor holding exactly the needed permission and one holding one fewer, and the
 * absence of a delete path is asserted by trying to reach one rather than by
 * reading the class.
 */
uses(RefreshDatabase::class);

const ARTICLE_RESOURCE_PERMISSIONS = [
    'view_any_article',
    'view_article',
    'create_article',
    'update_article',
    'publish_article',
    'unpublish_article',
];

/** A real PDF shaped for Livewire's upload simulator (see livewirePngUpload in Pest.php). */
function articleLivewirePdf(string $clientName = 'guide.pdf'): File
{
    return UploadedFile::fake()->createWithContent(
        $clientName,
        "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n",
    );
}

/** A private disk that reports every write as failed, with cleanup still working. */
function articleResourceFailDisk(): void
{
    $failing = Mockery::mock(Filesystem::class);
    $failing->shouldReceive('putFileAs')->andReturn(false);
    $failing->shouldReceive('delete')->andReturn(true);
    $failing->shouldReceive('exists')->andReturn(false);

    Storage::set('private', $failing);
}

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    Storage::fake('private');

    $this->roleUser = function (string $role): User {
        $user = User::factory()->create(['is_active' => true]);
        app(SystemRoleWriter::class)->assignRoles($user, $role);

        return $user->fresh();
    };

    // Exactly the named permissions, plus the panel door when asked for.
    $this->actorWith = function (string ...$permissions): User {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(...$permissions);

        return $user->fresh();
    };

    $this->admin = ($this->roleUser)('admin');

    $this->fill = fn (array $overrides = []): array => [
        'title_en' => 'Welding Safety Handbook',
        'description_en' => 'Protective equipment and ventilation for the workshop.',
        'topic' => 'Safety',
        'authors' => 'Hana Belaid',
        'issued_on' => '2025-11-03',
        'pdf_file' => articleLivewirePdf('handbook.pdf'),
        ...$overrides,
    ];
});

afterEach(function () {
    DB::disconnect(FileLifecycleService::compensationConnectionName());
});

/*
|--------------------------------------------------------------------------
| Permissions as seeded and as the policy answers them
|--------------------------------------------------------------------------
*/

it('seeds exactly the six article permissions and never a delete one', function () {
    $seeded = Permission::query()
        ->pluck('name')
        ->filter(fn (string $name): bool => str_ends_with($name, '_article'))
        ->sort()
        ->values()
        ->all();

    $expected = ARTICLE_RESOURCE_PERMISSIONS;
    sort($expected);

    expect($seeded)->toBe($expected);
});

it('grants the admin role all six, and staff and student none', function () {
    $held = fn (string $role): array => Role::findByName($role, 'web')
        ->permissions
        ->pluck('name')
        ->filter(fn (string $name): bool => str_ends_with($name, '_article'))
        ->sort()
        ->values()
        ->all();

    $expected = ARTICLE_RESOURCE_PERMISSIONS;
    sort($expected);

    expect($held('admin'))->toBe($expected)
        ->and($held('staff'))->toBe([])
        ->and($held('student'))->toBe([]);
});

it('resolves ArticlePolicy through the Gate without a registration', function () {
    // An unregistered policy that stopped resolving would fail silently, as a
    // false on every check.
    expect(Gate::getPolicyFor(Article::class))->toBeInstanceOf(ArticlePolicy::class);
});

it('refuses delete, force delete, restore and replicate even to a holder of every ability', function () {
    $article = Article::factory()->create();

    // The permission is NOT seeded. Create it here and grant it, to prove the
    // policy refuses regardless: "the permission does not exist" and "the policy
    // refuses" are different claims, and only the second survives somebody
    // seeding it.
    foreach (['delete_article', 'delete_any_article', 'force_delete_article', 'restore_article', 'replicate_article'] as $name) {
        Permission::findOrCreate($name, 'web');
    }

    $holder = ($this->actorWith)(
        ...ARTICLE_RESOURCE_PERMISSIONS,
        ...['delete_article', 'delete_any_article', 'force_delete_article', 'restore_article', 'replicate_article'],
    );
    $superAdmin = ($this->roleUser)('super_admin');

    foreach ([$holder, $superAdmin] as $actor) {
        foreach (['delete', 'restore', 'forceDelete', 'replicate'] as $ability) {
            expect(Gate::forUser($actor)->denies($ability, $article))->toBeTrue("{$ability} was allowed");
        }

        foreach (['deleteAny', 'restoreAny', 'forceDeleteAny', 'reorder'] as $ability) {
            expect(Gate::forUser($actor)->denies($ability, Article::class))->toBeTrue("{$ability} was allowed");
        }
    }
});

/*
|--------------------------------------------------------------------------
| Reaching the pages
|--------------------------------------------------------------------------
*/

it('lets an admin reach the list, create, view and edit pages', function () {
    $article = Article::factory()->create();

    $this->actingAs($this->admin);

    $this->get('/admin/articles')->assertSuccessful();
    $this->get('/admin/articles/create')->assertSuccessful();
    $this->get("/admin/articles/{$article->getKey()}")->assertSuccessful();
    $this->get("/admin/articles/{$article->getKey()}/edit")->assertSuccessful();
});

it('keeps staff out of every page', function () {
    $article = Article::factory()->create();

    $this->actingAs(($this->roleUser)('staff'));

    $this->get('/admin/articles')->assertForbidden();
    $this->get('/admin/articles/create')->assertForbidden();
    $this->get("/admin/articles/{$article->getKey()}")->assertForbidden();
    $this->get("/admin/articles/{$article->getKey()}/edit")->assertForbidden();
});

it('lets a view-only actor read the library but not change it', function () {
    $article = Article::factory()->create();
    $reader = ($this->actorWith)('access_admin_panel', 'view_any_article', 'view_article');

    $this->actingAs($reader);

    $this->get('/admin/articles')->assertSuccessful();
    $this->get("/admin/articles/{$article->getKey()}")->assertSuccessful();
    $this->get('/admin/articles/create')->assertForbidden();
    $this->get("/admin/articles/{$article->getKey()}/edit")->assertForbidden();
});

it('opens the create page to create_article, and the edit page to update_article, and neither to the other', function () {
    $article = Article::factory()->create();

    // view_any_article is the resource's door: Filament gates every page of a
    // resource on it before the page's own ability is asked. So each actor holds
    // the door plus exactly one page ability, and the pair is the other one.
    $this->actingAs(($this->actorWith)('access_admin_panel', 'view_any_article', 'create_article'));
    $this->get('/admin/articles/create')->assertSuccessful();
    $this->get("/admin/articles/{$article->getKey()}/edit")->assertForbidden();

    $this->actingAs(($this->actorWith)('access_admin_panel', 'view_any_article', 'update_article'));
    $this->get("/admin/articles/{$article->getKey()}/edit")->assertSuccessful();
    $this->get('/admin/articles/create')->assertForbidden();
});

it('shuts every page to an actor with write abilities but no view_any_article', function () {
    // The door itself: Shield's convention, and what keeps the nav entry and the
    // list off the screen of someone who may only act on a record they were sent.
    $article = Article::factory()->create();

    $this->actingAs(($this->actorWith)('access_admin_panel', 'create_article', 'update_article', 'publish_article'));

    $this->get('/admin/articles')->assertForbidden();
    $this->get('/admin/articles/create')->assertForbidden();
    $this->get("/admin/articles/{$article->getKey()}/edit")->assertForbidden();
});

/*
|--------------------------------------------------------------------------
| The list
|--------------------------------------------------------------------------
*/

it('lists articles with their publication state, searchable and filterable', function () {
    $live = Article::factory()->published()->create(['title_en' => 'Published Guide', 'topic' => 'Safety']);
    $draft = Article::factory()->create(['title_en' => 'Draft Guide', 'topic' => 'Welding']);

    Livewire::actingAs($this->admin)
        ->test(ListArticles::class)
        ->assertCanSeeTableRecords([$live, $draft])
        ->searchTable('Draft')
        ->assertCanSeeTableRecords([$draft])
        ->assertCanNotSeeTableRecords([$live])
        ->searchTable('')
        ->filterTable('published', true)
        ->assertCanSeeTableRecords([$live])
        ->assertCanNotSeeTableRecords([$draft])
        ->filterTable('published', false)
        ->assertCanSeeTableRecords([$draft])
        ->assertCanNotSeeTableRecords([$live]);
});

it('shows the download count as a plain stored number', function () {
    $article = Article::factory()->published()->create(['download_count' => 1234]);

    Livewire::actingAs($this->admin)
        ->test(ListArticles::class)
        ->assertTableColumnStateSet('download_count', 1234, $article);
});

/*
|--------------------------------------------------------------------------
| There is no delete path, for anyone
|--------------------------------------------------------------------------
*/

it('offers no delete or bulk action on the list, to an admin or a super admin', function (string $role) {
    $article = Article::factory()->published()->create();

    $list = Livewire::actingAs(($this->roleUser)($role))->test(ListArticles::class);

    $list->assertTableActionDoesNotExist('delete', null, $article)
        ->assertTableActionDoesNotExist('forceDelete', null, $article)
        ->assertTableActionDoesNotExist('restore', null, $article)
        ->assertTableBulkActionDoesNotExist('delete')
        ->assertTableBulkActionDoesNotExist('forceDelete')
        ->assertTableBulkActionDoesNotExist('restore');

    // And the raw server path, which does not consult what the page chose to render.
    try {
        $list->call('mountAction', 'delete', [], ['table' => true, 'recordKey' => (string) $article->getKey()]);
    } catch (Throwable) {
        // A component that refuses an unknown action outright is also a refusal.
    }

    expect(Article::query()->whereKey($article->getKey())->exists())->toBeTrue();
})->with(['admin', 'super_admin']);

it('offers no delete action on the edit or view page, to an admin or a super admin', function (string $role) {
    $article = Article::factory()->create();
    $actor = ($this->roleUser)($role);

    Livewire::actingAs($actor)
        ->test(EditArticle::class, ['record' => $article->getKey()])
        ->assertActionDoesNotExist('delete')
        ->assertActionDoesNotExist('forceDelete')
        ->assertActionDoesNotExist('restore');

    Livewire::actingAs($actor)
        ->test(ViewArticle::class, ['record' => $article->getKey()])
        ->assertActionDoesNotExist('delete')
        ->assertActionDoesNotExist('forceDelete')
        ->assertActionDoesNotExist('restore');

    expect(Article::query()->whereKey($article->getKey())->exists())->toBeTrue();
})->with(['admin', 'super_admin']);

it('registers no delete route either', function () {
    $article = Article::factory()->create();

    $this->actingAs(($this->roleUser)('super_admin'));

    // Filament registers only the pages the resource names. There is no
    // /delete, and a DELETE verb on the record URL reaches nothing.
    $this->get("/admin/articles/{$article->getKey()}/delete")->assertNotFound();
    $this->delete("/admin/articles/{$article->getKey()}")->assertStatus(405);

    expect(Article::query()->whereKey($article->getKey())->exists())->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Publish and unpublish are record actions behind their own permissions
|--------------------------------------------------------------------------
*/

it('shows publish only to an actor holding publish_article, and only for an unpublished article', function () {
    $draft = Article::factory()->create();
    $live = Article::factory()->published()->create();

    $publisher = ($this->actorWith)('view_any_article', 'publish_article');
    $notPublisher = ($this->actorWith)('view_any_article', 'unpublish_article');

    Livewire::actingAs($publisher)
        ->test(ListArticles::class)
        ->assertTableActionVisible('publish', $draft)
        // The state half: an already-published article offers no publish.
        ->assertTableActionHidden('publish', $live);

    // The permission half, on the very same unpublished record.
    Livewire::actingAs($notPublisher)
        ->test(ListArticles::class)
        ->assertTableActionHidden('publish', $draft);
});

it('shows unpublish only to an actor holding unpublish_article, and only for a published article', function () {
    $draft = Article::factory()->create();
    $live = Article::factory()->published()->create();

    $withdrawer = ($this->actorWith)('view_any_article', 'unpublish_article');
    $notWithdrawer = ($this->actorWith)('view_any_article', 'publish_article');

    Livewire::actingAs($withdrawer)
        ->test(ListArticles::class)
        ->assertTableActionVisible('unpublish', $live)
        ->assertTableActionHidden('unpublish', $draft);

    Livewire::actingAs($notWithdrawer)
        ->test(ListArticles::class)
        ->assertTableActionHidden('unpublish', $live);
});

it('shows neither lifecycle action to an actor holding neither permission', function () {
    $draft = Article::factory()->create();
    $live = Article::factory()->published()->create();

    Livewire::actingAs(($this->actorWith)('view_any_article', 'view_article', 'update_article'))
        ->test(ListArticles::class)
        ->assertTableActionHidden('publish', $draft)
        ->assertTableActionHidden('unpublish', $live);
});

it('does not mount a lifecycle action the actor lacks the permission for, even when asked directly', function () {
    $draft = Article::factory()->create();
    $live = Article::factory()->published()->create();

    $publisher = ($this->actorWith)('view_any_article', 'publish_article');

    // Drive the raw server mount: a helper that first asserted visibility would
    // prove only that the test helper refused, not the Livewire endpoint.
    $list = Livewire::actingAs($publisher)->test(ListArticles::class);
    $list->call('mountAction', 'unpublish', [], ['table' => true, 'recordKey' => (string) $live->getKey()]);

    expect($list->get('mountedActions'))->toBeEmpty();

    $list->call('callMountedAction');

    expect($live->fresh()->isPublished())->toBeTrue();

    $withdrawer = ($this->actorWith)('view_any_article', 'unpublish_article');

    $list = Livewire::actingAs($withdrawer)->test(ListArticles::class);
    $list->call('mountAction', 'publish', [], ['table' => true, 'recordKey' => (string) $draft->getKey()]);

    expect($list->get('mountedActions'))->toBeEmpty();

    $list->call('callMountedAction');

    expect($draft->fresh()->isPublished())->toBeFalse();
});

it('publishes and unpublishes through the record actions, attributing each to the actor', function () {
    $article = Article::factory()->create();
    $actor = ($this->actorWith)('view_any_article', 'publish_article', 'unpublish_article');

    Livewire::actingAs($actor)
        ->test(ListArticles::class)
        ->callTableAction('publish', $article)
        ->assertNotified(__('publications.published_successfully'));

    expect($article->fresh()->isPublished())->toBeTrue();

    Livewire::actingAs($actor)
        ->test(ListArticles::class)
        ->callTableAction('unpublish', $article)
        ->assertNotified(__('publications.unpublished_successfully'));

    expect($article->fresh()->isPublished())->toBeFalse();

    $causers = Activity::query()
        ->where('subject_type', Article::class)
        ->where('subject_id', $article->getKey())
        ->where('event', 'updated')
        ->orderBy('id')
        ->pluck('causer_id')
        ->map(fn ($id): int => (int) $id)
        ->all();

    expect($causers)->toBe([(int) $actor->getKey(), (int) $actor->getKey()]);
});

it('offers view and edit row actions according to the matching permission', function () {
    $article = Article::factory()->create();

    Livewire::actingAs(($this->actorWith)('view_any_article', 'view_article'))
        ->test(ListArticles::class)
        ->assertTableActionVisible('view', $article)
        ->assertTableActionHidden('edit', $article);

    Livewire::actingAs($this->admin)
        ->test(ListArticles::class)
        ->assertTableActionVisible('view', $article)
        ->assertTableActionVisible('edit', $article);
});

/*
|--------------------------------------------------------------------------
| Create
|--------------------------------------------------------------------------
*/

it('creates an unpublished article through the Action, with its PDF on the private disk', function () {
    Livewire::actingAs($this->admin)
        ->test(CreateArticle::class)
        ->fillForm(($this->fill)())
        ->call('create')
        ->assertHasNoFormErrors();

    $article = Article::query()->sole();

    expect($article->title_en)->toBe('Welding Safety Handbook')
        ->and($article->slug)->toBe('welding-safety-handbook')
        ->and($article->topic)->toBe('Safety')
        ->and($article->authors)->toBe('Hana Belaid')
        ->and($article->issued_on->toDateString())->toBe('2025-11-03')
        ->and($article->isPublished())->toBeFalse()
        ->and($article->disk)->toBe('private')
        ->and($article->path)->toMatch('/^publications\/[0-9A-Za-z]{26}\.pdf$/')
        ->and($article->original_filename)->toBe('handbook.pdf');

    Storage::disk('private')->assertExists($article->path);

    $activity = Activity::query()
        ->where('subject_type', Article::class)
        ->where('subject_id', $article->getKey())
        ->where('event', 'created')
        ->sole();

    expect((int) $activity->causer_id)->toBe((int) $this->admin->getKey());
});

it('offers no slug, publication or counter field when creating', function () {
    Livewire::actingAs($this->admin)
        ->test(CreateArticle::class)
        ->assertFormFieldIsHidden('slug')
        ->assertFormFieldDoesNotExist('published_at')
        ->assertFormFieldDoesNotExist('download_count')
        ->assertFormFieldDoesNotExist('path')
        ->assertFormFieldDoesNotExist('disk');
});

it('refuses to create without a PDF', function () {
    Livewire::actingAs($this->admin)
        ->test(CreateArticle::class)
        ->fillForm(($this->fill)(['pdf_file' => null]))
        ->call('create')
        ->assertHasFormErrors(['pdf_file' => 'required']);

    expect(Article::count())->toBe(0);
});

it('refuses to create from something that is not a PDF', function () {
    Livewire::actingAs($this->admin)
        ->test(CreateArticle::class)
        ->fillForm(($this->fill)(['pdf_file' => livewirePngUpload('cover.png')]))
        ->call('create');

    expect(Article::count())->toBe(0)
        ->and(Storage::disk('private')->allFiles())->toBe([]);
});

it('refuses the create page to an actor without create_article', function () {
    $almost = ($this->actorWith)(
        'view_any_article',
        'view_article',
        'update_article',
        'publish_article',
        'unpublish_article',
    );

    Livewire::actingAs($almost)
        ->test(CreateArticle::class)
        ->assertForbidden();

    expect(Article::count())->toBe(0);
});

it('tells the administrator and saves nothing when the disk cannot take the PDF', function () {
    articleResourceFailDisk();

    Livewire::actingAs($this->admin)
        ->test(CreateArticle::class)
        ->fillForm(($this->fill)())
        ->call('create')
        ->assertNotified(__('publications.storage_unavailable'));

    expect(Article::count())->toBe(0)
        ->and(PendingFileDeletion::count())->toBe(0);
});

/*
|--------------------------------------------------------------------------
| Edit
|--------------------------------------------------------------------------
*/

it('edits an article through the Action, attributing the change to the actor', function () {
    $article = Article::factory()->create(['title_en' => 'Old title', 'topic' => 'Welding']);

    Livewire::actingAs($this->admin)
        ->test(EditArticle::class, ['record' => $article->getKey()])
        ->fillForm(['title_en' => 'New title'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($article->fresh()->title_en)->toBe('New title')
        ->and($article->fresh()->topic)->toBe('Welding');

    $activity = Activity::query()
        ->where('subject_type', Article::class)
        ->where('subject_id', $article->getKey())
        ->where('event', 'updated')
        ->sole();

    expect((int) $activity->causer_id)->toBe((int) $this->admin->getKey())
        ->and($activity->attribute_changes['old']['title_en'])->toBe('Old title');
});

it('refuses the edit page to an actor without update_article', function () {
    $article = Article::factory()->create();

    $almost = ($this->actorWith)(
        'view_any_article',
        'view_article',
        'create_article',
        'publish_article',
        'unpublish_article',
    );

    Livewire::actingAs($almost)
        ->test(EditArticle::class, ['record' => $article->getKey()])
        ->assertForbidden();
});

it('lets an unpublished article change its web address', function () {
    $article = Article::factory()->create(['slug' => 'old-address']);

    Livewire::actingAs($this->admin)
        ->test(EditArticle::class, ['record' => $article->getKey()])
        ->assertFormFieldIsEnabled('slug')
        ->fillForm(['slug' => 'new-address'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($article->fresh()->slug)->toBe('new-address');
});

it('locks the web address of a published article, and ignores one sent anyway', function () {
    $article = Article::factory()->published()->create(['slug' => 'shared-link', 'title_en' => 'Before']);

    Livewire::actingAs($this->admin)
        ->test(EditArticle::class, ['record' => $article->getKey()])
        ->assertFormFieldIsDisabled('slug')
        // A request that carries a slug for a field the page rendered disabled.
        ->set('data.slug', 'hijacked-link')
        ->fillForm(['title_en' => 'After'])
        ->call('save');

    $fresh = $article->fresh();

    expect($fresh->slug)->toBe('shared-link')
        ->and($fresh->title_en)->toBe('After')
        ->and($fresh->isPublished())->toBeTrue();
});

it('rejects a web address another article already uses', function () {
    Article::factory()->create(['slug' => 'taken']);
    $article = Article::factory()->create(['slug' => 'mine']);

    Livewire::actingAs($this->admin)
        ->test(EditArticle::class, ['record' => $article->getKey()])
        ->fillForm(['slug' => 'taken'])
        ->call('save')
        ->assertHasFormErrors(['slug']);

    expect($article->fresh()->slug)->toBe('mine');
});

it('keeps the file when the edit sends none', function () {
    Storage::disk('private')->put('publications/keep-me.pdf', 'original-bytes');
    $article = Article::factory()->create(['path' => 'publications/keep-me.pdf']);

    Livewire::actingAs($this->admin)
        ->test(EditArticle::class, ['record' => $article->getKey()])
        ->fillForm(['title_en' => 'Retitled'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($article->fresh()->path)->toBe('publications/keep-me.pdf')
        ->and(Storage::disk('private')->get('publications/keep-me.pdf'))->toBe('original-bytes')
        ->and(PendingFileDeletion::count())->toBe(0);
});

it('replaces the PDF from the edit page and removes the old file once it commits', function () {
    Storage::disk('private')->put('publications/old-file.pdf', 'old-bytes');
    $article = Article::factory()->create([
        'path' => 'publications/old-file.pdf',
        'original_filename' => 'old.pdf',
    ]);

    Livewire::actingAs($this->admin)
        ->test(EditArticle::class, ['record' => $article->getKey()])
        ->fillForm(['pdf_file' => articleLivewirePdf('replacement.pdf')])
        ->call('save')
        ->assertHasNoFormErrors();

    $fresh = $article->fresh();

    expect($fresh->path)->not->toBe('publications/old-file.pdf')
        ->and($fresh->path)->toMatch('/^publications\/[0-9A-Za-z]{26}\.pdf$/')
        ->and($fresh->original_filename)->toBe('replacement.pdf')
        ->and($fresh->disk)->toBe('private');

    Storage::disk('private')->assertExists($fresh->path);
    Storage::disk('private')->assertMissing('publications/old-file.pdf');
});

it('tells the administrator and changes nothing when the disk cannot take a replacement PDF', function () {
    $article = Article::factory()->create(['title_en' => 'Original title', 'path' => 'publications/old-file.pdf']);

    articleResourceFailDisk();

    Livewire::actingAs($this->admin)
        ->test(EditArticle::class, ['record' => $article->getKey()])
        ->fillForm([
            'title_en' => 'Edited title',
            'pdf_file' => articleLivewirePdf('replacement.pdf'),
        ])
        ->call('save')
        ->assertNotified(__('publications.storage_unavailable'));

    $fresh = $article->fresh();

    // Nothing half-saved: the title the administrator typed did not commit
    // alongside a PDF that did not.
    expect($fresh->title_en)->toBe('Original title')
        ->and($fresh->path)->toBe('publications/old-file.pdf');
});

/*
|--------------------------------------------------------------------------
| View
|--------------------------------------------------------------------------
*/

it('shows a view-only actor the article without a way to change it', function () {
    $article = Article::factory()->create(['title_en' => 'Read only title']);

    Livewire::actingAs(($this->actorWith)('view_any_article', 'view_article'))
        ->test(ViewArticle::class, ['record' => $article->getKey()])
        ->assertSuccessful()
        ->assertFormSet(['title_en' => 'Read only title'])
        ->assertActionHidden('edit');

    Livewire::actingAs($this->admin)
        ->test(ViewArticle::class, ['record' => $article->getKey()])
        ->assertActionVisible('edit');
});
