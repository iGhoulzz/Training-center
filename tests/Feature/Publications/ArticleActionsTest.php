<?php

declare(strict_types=1);

use App\Domain\Publications\Actions\CreateArticleAction;
use App\Domain\Publications\Actions\PublishArticleAction;
use App\Domain\Publications\Actions\UnpublishArticleAction;
use App\Domain\Publications\Actions\UpdateArticleAction;
use App\Domain\Publications\Models\Article;
use App\Domain\Staff\Services\FileLifecycleService;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/**
 * The four staff writes on an article: create, update, publish, unpublish.
 *
 * AUTHORIZATION IS TESTED AS AN ACTOR HOLDING EXACTLY THE NEEDED PERMISSION AND AS
 * ONE HOLDING EVERYTHING BUT IT. Never as a super admin: that role holds every
 * permission, so it passes whichever ability the code checks and proves nothing
 * about which one it is. The permission list below is written out by hand rather
 * than read back from the seeder, so a permission the seeder dropped fails here
 * instead of agreeing with itself.
 */
uses(RefreshDatabase::class);

const ARTICLE_PERMISSIONS = [
    'view_any_article',
    'view_article',
    'create_article',
    'update_article',
    'publish_article',
    'unpublish_article',
];

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);

    Storage::fake('private');

    // An actor holding exactly the named permissions and nothing else.
    $this->actorWith = function (string ...$permissions): User {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(...$permissions);

        return $user->fresh();
    };

    // An actor holding every article permission except the named one.
    $this->actorWithAllBut = function (string $missing): User {
        $held = array_values(array_diff(ARTICLE_PERMISSIONS, [$missing]));

        // The control: exactly one permission is missing, so a typo in $missing
        // could not silently hand this actor all six.
        expect($held)->toHaveCount(5);

        return ($this->actorWith)(...$held);
    };

    $this->metadata = fn (array $overrides = []): array => [
        'title_en' => 'Modern Welding Techniques',
        'title_ar' => null,
        'description_en' => 'A survey of arc and MIG welding methods.',
        'description_ar' => null,
        'topic' => 'Welding',
        'authors' => 'Layla Mansour',
        'issued_on' => '2025-11-03',
        ...$overrides,
    ];
});

afterEach(function () {
    Carbon::setTestNow();

    DB::disconnect(FileLifecycleService::compensationConnectionName());
});

/*
|--------------------------------------------------------------------------
| CreateArticleAction
|--------------------------------------------------------------------------
*/

it('creates an unpublished article for an actor holding exactly create_article', function () {
    $actor = ($this->actorWith)('create_article');

    $article = app(CreateArticleAction::class)->execute(
        $actor,
        ($this->metadata)(),
        pdfUpload('welding-guide.pdf'),
    );

    expect($article->exists)->toBeTrue()
        ->and($article->title_en)->toBe('Modern Welding Techniques')
        ->and($article->slug)->toBe('modern-welding-techniques')
        ->and($article->topic)->toBe('Welding')
        ->and($article->authors)->toBe('Layla Mansour')
        ->and($article->issued_on->toDateString())->toBe('2025-11-03')
        ->and($article->original_filename)->toBe('welding-guide.pdf')
        // Never published by creating: publishing is its own act and permission.
        ->and($article->published_at)->toBeNull()
        ->and($article->isPublished())->toBeFalse()
        // The database default, visible on the returned instance.
        ->and($article->download_count)->toBe(0);
});

it('refuses to create for an actor holding every article permission but create_article', function () {
    $actor = ($this->actorWithAllBut)('create_article');

    expect(fn () => app(CreateArticleAction::class)->execute(
        $actor,
        ($this->metadata)(),
        pdfUpload(),
    ))->toThrow(AuthorizationException::class);

    // Refused before anything was written: no row, and no bytes on the disk.
    expect(Article::count())->toBe(0)
        ->and(Storage::disk('private')->allFiles())->toBe([]);
});

it('logs the creation against the actor who performed it', function () {
    $actor = ($this->actorWith)('create_article');
    $bystander = ($this->actorWith)('view_article');

    // A different user is signed in. The entry must name the explicit actor, not
    // whoever the session happens to hold.
    $this->actingAs($bystander);

    $article = app(CreateArticleAction::class)->execute($actor, ($this->metadata)(), pdfUpload());

    $activity = Activity::query()
        ->where('subject_type', Article::class)
        ->where('subject_id', $article->getKey())
        ->where('event', 'created')
        ->sole();

    expect((int) $activity->causer_id)->toBe((int) $actor->getKey())
        ->and($activity->attribute_changes['attributes']['title_en'])->toBe('Modern Welding Techniques')
        ->and($activity->attribute_changes['attributes']['topic'])->toBe('Welding')
        // The anonymous counter is not an audited fact.
        ->and($activity->attribute_changes['attributes'])->not->toHaveKey('download_count');
});

it('gives a title that collides with an existing slug a short random suffix', function () {
    $actor = ($this->actorWith)('create_article');

    $first = app(CreateArticleAction::class)->execute($actor, ($this->metadata)(), pdfUpload());
    $second = app(CreateArticleAction::class)->execute($actor, ($this->metadata)(), pdfUpload());

    expect($first->slug)->toBe('modern-welding-techniques')
        ->and($second->slug)->toMatch('/^modern-welding-techniques-[a-z0-9]{6}$/')
        ->and($second->slug)->not->toBe($first->slug)
        ->and(Article::count())->toBe(2);
});

it('falls back to a plain slug when the title has nothing to slug', function () {
    $article = app(CreateArticleAction::class)->execute(
        ($this->actorWith)('create_article'),
        ($this->metadata)(['title_en' => '!!! ???']),
        pdfUpload(),
    );

    expect($article->slug)->toBe('article');
});

it('stores a blank Arabic field as not translated rather than as an empty translation', function () {
    $article = app(CreateArticleAction::class)->execute(
        ($this->actorWith)('create_article'),
        ($this->metadata)(['title_ar' => '', 'description_ar' => '   ']),
        pdfUpload(),
    );

    expect($article->title_ar)->toBeNull()
        ->and($article->description_ar)->toBeNull();
});

it('ignores crafted storage identity, slug, publication state and counter in the metadata', function () {
    $article = app(CreateArticleAction::class)->execute(
        ($this->actorWith)('create_article'),
        ($this->metadata)([
            'disk' => 'public',
            'path' => '../../../../etc/passwd',
            'original_filename' => '../../secret.pdf',
            'slug' => 'chosen-by-the-client',
            'published_at' => '2026-01-01 00:00:00',
            'download_count' => 9000,
        ]),
        pdfUpload('real-name.pdf'),
    );

    expect($article->disk)->toBe('private')
        ->and($article->path)->toMatch('/^publications\/[0-9A-Za-z]{26}\.pdf$/')
        ->and($article->path)->not->toContain('..')
        ->and($article->original_filename)->toBe('real-name.pdf')
        ->and($article->slug)->toBe('modern-welding-techniques')
        ->and($article->published_at)->toBeNull()
        ->and($article->download_count)->toBe(0);
});

it('refuses invalid details and writes nothing', function (array $overrides) {
    $actor = ($this->actorWith)('create_article');

    expect(fn () => app(CreateArticleAction::class)->execute(
        $actor,
        ($this->metadata)($overrides),
        pdfUpload(),
    ))->toThrow(ValidationException::class);

    expect(Article::count())->toBe(0)
        ->and(Storage::disk('private')->allFiles())->toBe([]);
})->with([
    'no English title' => [['title_en' => '']],
    'a title over 200 characters' => [['title_en' => str_repeat('a', 201)]],
    'no description' => [['description_en' => '']],
    'no topic' => [['topic' => '']],
    'no authors' => [['authors' => '']],
    'no issue date' => [['issued_on' => null]],
    'a date that is not a date' => [['issued_on' => 'last spring']],
    'an issue date in the far future' => [['issued_on' => '2999-01-01']],
]);

it('accepts nothing but a real PDF, judged by its bytes and not its name', function (string $name, string $bytes) {
    $actor = ($this->actorWith)('create_article');

    expect(fn () => app(CreateArticleAction::class)->execute(
        $actor,
        ($this->metadata)(),
        uploadWithBytes($name, $bytes),
    ))->toThrow(ValidationException::class);

    expect(Article::count())->toBe(0)
        ->and(Storage::disk('private')->allFiles())->toBe([]);
})->with([
    'plain text wearing a .pdf name' => ['paper.pdf', 'just plain text, not a pdf at all'],
    'a PHP script wearing a .pdf name' => ['paper.pdf', '<?php echo "hello";'],
    'a PNG image' => ['cover.png', makePngBytes()],
]);

/*
|--------------------------------------------------------------------------
| UpdateArticleAction
|--------------------------------------------------------------------------
*/

it('updates an article for an actor holding exactly update_article, and logs the change', function () {
    $article = Article::factory()->create(['title_en' => 'Old title', 'topic' => 'Safety']);
    $actor = ($this->actorWith)('update_article');
    $bystander = ($this->actorWith)('view_article');

    $this->actingAs($bystander);

    $updated = app(UpdateArticleAction::class)->execute(
        $actor,
        $article,
        ($this->metadata)(['title_en' => 'New title', 'topic' => 'Safety']),
    );

    expect($updated->title_en)->toBe('New title')
        ->and($article->fresh()->title_en)->toBe('New title')
        ->and($article->fresh()->authors)->toBe('Layla Mansour');

    $activity = Activity::query()
        ->where('subject_type', Article::class)
        ->where('subject_id', $article->getKey())
        ->where('event', 'updated')
        ->sole();

    expect((int) $activity->causer_id)->toBe((int) $actor->getKey())
        ->and($activity->attribute_changes['attributes']['title_en'])->toBe('New title')
        ->and($activity->attribute_changes['old']['title_en'])->toBe('Old title')
        // Only what moved is in the diff.
        ->and($activity->attribute_changes['attributes'])->not->toHaveKey('topic');
});

it('refuses to update for an actor holding every article permission but update_article', function () {
    $article = Article::factory()->create(['title_en' => 'Untouched']);
    $actor = ($this->actorWithAllBut)('update_article');

    expect(fn () => app(UpdateArticleAction::class)->execute(
        $actor,
        $article,
        ($this->metadata)(['title_en' => 'Hijacked']),
    ))->toThrow(AuthorizationException::class);

    expect($article->fresh()->title_en)->toBe('Untouched');
});

it('leaves the publication state and the counter alone when it updates', function () {
    $article = Article::factory()->published()->create([
        'published_at' => '2026-01-15 10:00:00',
        'download_count' => 41,
    ]);

    app(UpdateArticleAction::class)->execute(
        ($this->actorWith)('update_article'),
        $article,
        ($this->metadata)([
            'published_at' => null,
            'download_count' => 0,
        ]),
    );

    $fresh = $article->fresh();

    expect($fresh->published_at->toDateTimeString())->toBe('2026-01-15 10:00:00')
        ->and($fresh->download_count)->toBe(41);
});

it('keeps the slug when none is submitted', function () {
    $article = Article::factory()->create(['slug' => 'stays-put']);

    app(UpdateArticleAction::class)->execute(
        ($this->actorWith)('update_article'),
        $article,
        ($this->metadata)(['title_en' => 'A completely different title']),
    );

    expect($article->fresh()->slug)->toBe('stays-put');
});

it('lets an unpublished article change its slug, normalised', function () {
    $article = Article::factory()->create(['slug' => 'old-address']);

    app(UpdateArticleAction::class)->execute(
        ($this->actorWith)('update_article'),
        $article,
        ($this->metadata)(['slug' => 'My New Address']),
    );

    expect($article->fresh()->slug)->toBe('my-new-address');
});

it('refuses a different slug for a published article and changes nothing', function () {
    $article = Article::factory()->published()->create([
        'slug' => 'shared-link',
        'title_en' => 'Before',
    ]);

    expect(fn () => app(UpdateArticleAction::class)->execute(
        ($this->actorWith)('update_article'),
        $article,
        ($this->metadata)(['title_en' => 'After', 'slug' => 'a-different-link']),
    ))->toThrow(ValidationException::class);

    // The whole edit is refused, not just the slug: nothing half-applied.
    $fresh = $article->fresh();

    expect($fresh->slug)->toBe('shared-link')
        ->and($fresh->title_en)->toBe('Before');
});

it('accepts the same slug for a published article, so a read-only form field is harmless', function () {
    $article = Article::factory()->published()->create(['slug' => 'shared-link']);

    app(UpdateArticleAction::class)->execute(
        ($this->actorWith)('update_article'),
        $article,
        ($this->metadata)(['title_en' => 'Corrected title', 'slug' => 'shared-link']),
    );

    expect($article->fresh()->title_en)->toBe('Corrected title')
        ->and($article->fresh()->slug)->toBe('shared-link');
});

it('refuses a slug another article already uses', function () {
    Article::factory()->create(['slug' => 'taken']);
    $article = Article::factory()->create(['slug' => 'mine']);

    expect(fn () => app(UpdateArticleAction::class)->execute(
        ($this->actorWith)('update_article'),
        $article,
        ($this->metadata)(['slug' => 'taken']),
    ))->toThrow(ValidationException::class);

    expect($article->fresh()->slug)->toBe('mine');
});

it('refuses an update with a slug that normalises to nothing', function () {
    $article = Article::factory()->create(['slug' => 'mine']);

    expect(fn () => app(UpdateArticleAction::class)->execute(
        ($this->actorWith)('update_article'),
        $article,
        ($this->metadata)(['slug' => '!!!']),
    ))->toThrow(ValidationException::class);

    expect($article->fresh()->slug)->toBe('mine');
});

/*
|--------------------------------------------------------------------------
| PublishArticleAction
|--------------------------------------------------------------------------
*/

it('publishes an article for an actor holding exactly publish_article, and logs it', function () {
    Carbon::setTestNow('2026-03-01 09:30:00');

    $article = Article::factory()->create();
    $actor = ($this->actorWith)('publish_article');
    $bystander = ($this->actorWith)('view_article');

    $this->actingAs($bystander);

    $published = app(PublishArticleAction::class)->execute($actor, $article);

    expect($published->isPublished())->toBeTrue()
        ->and($article->fresh()->published_at->toDateTimeString())->toBe('2026-03-01 09:30:00')
        ->and(Article::query()->published()->pluck('id')->all())->toBe([$article->getKey()]);

    $activity = Activity::query()
        ->where('subject_type', Article::class)
        ->where('subject_id', $article->getKey())
        ->where('event', 'updated')
        ->sole();

    expect((int) $activity->causer_id)->toBe((int) $actor->getKey())
        ->and($activity->attribute_changes['old']['published_at'])->toBeNull()
        ->and($activity->attribute_changes['attributes']['published_at'])->not->toBeNull()
        ->and($activity->attribute_changes['attributes'])->toHaveCount(1);
});

it('refuses to publish for an actor holding every article permission but publish_article', function () {
    $article = Article::factory()->create();
    $actor = ($this->actorWithAllBut)('publish_article');

    expect(fn () => app(PublishArticleAction::class)->execute($actor, $article))
        ->toThrow(AuthorizationException::class);

    expect($article->fresh()->published_at)->toBeNull();
});

it('refuses to publish an article that is already published, and keeps its date', function () {
    $article = Article::factory()->create(['published_at' => '2026-01-15 10:00:00']);

    expect(fn () => app(PublishArticleAction::class)->execute(
        ($this->actorWith)('publish_article'),
        $article,
    ))->toThrow(ValidationException::class);

    expect($article->fresh()->published_at->toDateTimeString())->toBe('2026-01-15 10:00:00');
});

it('refuses the second of two publications made from stale copies of the same article', function () {
    $article = Article::factory()->create();
    $actor = ($this->actorWith)('publish_article');

    // Two administrators open the same unpublished article.
    $first = Article::query()->findOrFail($article->getKey());
    $second = Article::query()->findOrFail($article->getKey());

    app(PublishArticleAction::class)->execute($actor, $first);

    // $second still believes it is unpublished. The Action re-reads under lock.
    expect($second->isPublished())->toBeFalse();
    expect(fn () => app(PublishArticleAction::class)->execute($actor, $second))
        ->toThrow(ValidationException::class);

    expect(Activity::query()->where('subject_type', Article::class)->where('event', 'updated')->count())->toBe(1);
});

it('cannot be published by mass assignment, whatever a form sends', function () {
    $article = Article::query()->create([
        'title_en' => 'Mass assigned',
        'slug' => 'mass-assigned',
        'description_en' => 'x',
        'topic' => 'Welding',
        'authors' => 'Nobody',
        'issued_on' => '2025-01-01',
        'original_filename' => 'x.pdf',
        'disk' => 'private',
        'path' => 'publications/x.pdf',
        'published_at' => '2026-01-01 00:00:00',
    ]);

    expect($article->fresh()->published_at)->toBeNull();

    $article->update(['published_at' => '2026-01-01 00:00:00']);

    expect($article->fresh()->published_at)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| UnpublishArticleAction
|--------------------------------------------------------------------------
*/

it('unpublishes an article for an actor holding exactly unpublish_article, and logs it', function () {
    $article = Article::factory()->create(['published_at' => '2026-01-15 10:00:00']);
    $actor = ($this->actorWith)('unpublish_article');
    $bystander = ($this->actorWith)('view_article');

    $this->actingAs($bystander);

    $withdrawn = app(UnpublishArticleAction::class)->execute($actor, $article);

    expect($withdrawn->isPublished())->toBeFalse()
        ->and($article->fresh()->published_at)->toBeNull()
        ->and(Article::query()->published()->count())->toBe(0);

    $activity = Activity::query()
        ->where('subject_type', Article::class)
        ->where('subject_id', $article->getKey())
        ->where('event', 'updated')
        ->sole();

    expect((int) $activity->causer_id)->toBe((int) $actor->getKey())
        ->and($activity->attribute_changes['old']['published_at'])->not->toBeNull()
        ->and($activity->attribute_changes['attributes']['published_at'])->toBeNull();
});

it('refuses to unpublish for an actor holding every article permission but unpublish_article', function () {
    $article = Article::factory()->published()->create();
    $actor = ($this->actorWithAllBut)('unpublish_article');

    expect(fn () => app(UnpublishArticleAction::class)->execute($actor, $article))
        ->toThrow(AuthorizationException::class);

    expect($article->fresh()->isPublished())->toBeTrue();
});

it('does not let publish_article alone take an article down', function () {
    // The pair to "unpublish_article alone cannot publish": the two are separate
    // grants, and neither implies the other.
    $article = Article::factory()->published()->create();

    expect(fn () => app(UnpublishArticleAction::class)->execute(
        ($this->actorWith)('publish_article'),
        $article,
    ))->toThrow(AuthorizationException::class);

    expect($article->fresh()->isPublished())->toBeTrue();
});

it('does not let unpublish_article alone put an article in front of readers', function () {
    $article = Article::factory()->create();

    expect(fn () => app(PublishArticleAction::class)->execute(
        ($this->actorWith)('unpublish_article'),
        $article,
    ))->toThrow(AuthorizationException::class);

    expect($article->fresh()->isPublished())->toBeFalse();
});

it('refuses to unpublish an article that is not published', function () {
    $article = Article::factory()->create();

    expect(fn () => app(UnpublishArticleAction::class)->execute(
        ($this->actorWith)('unpublish_article'),
        $article,
    ))->toThrow(ValidationException::class);

    expect($article->fresh()->published_at)->toBeNull();
});

it('can republish a withdrawn article, with a new date', function () {
    Carbon::setTestNow('2026-05-05 12:00:00');

    $article = Article::factory()->create(['published_at' => '2026-01-15 10:00:00']);
    $actor = ($this->actorWith)('publish_article', 'unpublish_article');

    app(UnpublishArticleAction::class)->execute($actor, $article);
    app(PublishArticleAction::class)->execute($actor, $article);

    expect($article->fresh()->published_at->toDateTimeString())->toBe('2026-05-05 12:00:00');
});

it('leaves a published article published and unchanged when a different field is edited', function () {
    // The mirror of the update test above: lifecycle Actions touch published_at
    // and nothing else.
    $article = Article::factory()->create(['title_en' => 'Keep me', 'topic' => 'Safety']);

    app(PublishArticleAction::class)->execute(($this->actorWith)('publish_article'), $article);

    $fresh = $article->fresh();

    expect($fresh->title_en)->toBe('Keep me')
        ->and($fresh->topic)->toBe('Safety')
        ->and($fresh->slug)->toBe($article->slug);
});
