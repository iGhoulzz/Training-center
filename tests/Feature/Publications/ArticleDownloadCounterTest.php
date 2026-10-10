<?php

declare(strict_types=1);

use App\Domain\Publications\Models\Article;
use App\Domain\Publications\Support\ArticleDownloadCounter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/**
 * The anonymous download counter: the one owner-approved write outside an
 * actor-first Action (docs/ENGINEERING.md, "The one exception").
 *
 * Its bounds are exactly what is asserted here: one conditional increment, only
 * for a published article, atomic, no actor, no activity-log row, no other column
 * touched. ArticleWriteBoundaryArchTest proves nothing else writes the column.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->counter = fn (Article $article): bool => app(ArticleDownloadCounter::class)->increment($article);
});

it('counts one download of a published article', function () {
    $article = Article::factory()->published()->create(['download_count' => 5]);

    $changed = ($this->counter)($article);

    expect($changed)->toBeTrue()
        ->and($article->fresh()->download_count)->toBe(6);
});

it('counts from zero', function () {
    $article = Article::factory()->published()->create();

    ($this->counter)($article);

    expect($article->fresh()->download_count)->toBe(1);
});

it('does not count an unpublished article', function () {
    $article = Article::factory()->create(['download_count' => 5]);

    $changed = ($this->counter)($article);

    expect($changed)->toBeFalse()
        ->and($article->fresh()->download_count)->toBe(5);
});

it('adds two when it is called twice', function () {
    $article = Article::factory()->published()->create(['download_count' => 10]);

    ($this->counter)($article);
    ($this->counter)($article);

    expect($article->fresh()->download_count)->toBe(12);
});

it('adds two for two callers holding stale copies of the same article', function () {
    // The property a read-modify-write would lose: both callers read the count as
    // 3, and an `UPDATE ... SET download_count = <3 + 1>` from each would land on
    // 4. The increment is computed by the database, so neither copy's idea of the
    // count matters.
    $article = Article::factory()->published()->create(['download_count' => 3]);

    $first = Article::query()->findOrFail($article->getKey());
    $second = Article::query()->findOrFail($article->getKey());

    expect($first->download_count)->toBe(3)
        ->and($second->download_count)->toBe(3);

    ($this->counter)($first);
    ($this->counter)($second);

    expect($article->fresh()->download_count)->toBe(5);
});

it('re-reads publication state in the statement, so a stale copy cannot count a withdrawn article', function () {
    $article = Article::factory()->published()->create(['download_count' => 2]);

    // A reader's request loaded the article while it was published...
    $stale = Article::query()->findOrFail($article->getKey());
    expect($stale->isPublished())->toBeTrue();

    // ...and it was withdrawn before the count was written. Raw query on purpose:
    // the test, not an Action, plays the part of the other request.
    DB::table('articles')->where('id', $article->getKey())->update(['published_at' => null]);

    $changed = ($this->counter)($stale);

    expect($changed)->toBeFalse()
        ->and($article->fresh()->download_count)->toBe(2);
});

it('returns false for an article whose row no longer exists', function () {
    $article = Article::factory()->published()->create(['download_count' => 4]);

    DB::table('articles')->where('id', $article->getKey())->delete();

    expect(($this->counter)($article))->toBeFalse();
});

it('counts only the article it is given', function () {
    $counted = Article::factory()->published()->create(['download_count' => 1]);
    $neighbour = Article::factory()->published()->create(['download_count' => 1]);

    ($this->counter)($counted);

    expect($counted->fresh()->download_count)->toBe(2)
        ->and($neighbour->fresh()->download_count)->toBe(1);
});

it('issues a single UPDATE and nothing else', function () {
    $article = Article::factory()->published()->create();

    $statements = captureStatements();

    ($this->counter)($article);

    // One statement: no SELECT first, so no read-modify-write and nothing for two
    // callers to race on. Written out by hand — the shape docs/ENGINEERING.md
    // gives, with the table-qualified key that whereKey() produces.
    expect($statements)->toHaveCount(1, 'The counter ran more than one statement: '.describeStatements($statements));

    $statement = $statements[0];

    expect($statement['sql'])->toBe(
        'update `articles` set `download_count` = `download_count` + 1 '
        .'where `articles`.`id` = ? and `published_at` is not null',
    )->and($statement['bindings'])->toBe([$article->getKey()]);
});

it('touches no column but the counter, updated_at included', function () {
    $article = Article::factory()->published()->create([
        'updated_at' => '2026-01-01 08:00:00',
        'title_en' => 'Unchanged title',
    ]);

    ($this->counter)($article);
    ($this->counter)($article);

    $fresh = $article->fresh();

    // An Eloquent increment() appends updated_at; this one must not, or every
    // anonymous download would look like an edit of the article.
    expect($fresh->updated_at->toDateTimeString())->toBe('2026-01-01 08:00:00')
        ->and($fresh->title_en)->toBe('Unchanged title')
        ->and($fresh->download_count)->toBe(2);
});

it('writes no activity-log entry', function () {
    $article = Article::factory()->published()->create();

    // The factory's own insert is logged; count after it.
    $before = Activity::query()->count();

    ($this->counter)($article);
    ($this->counter)($article);

    expect(Activity::query()->count())->toBe($before);
});

it('does not take an actor: the signature is the article alone', function () {
    $parameters = (new ReflectionMethod(ArticleDownloadCounter::class, 'increment'))->getParameters();

    expect($parameters)->toHaveCount(1)
        ->and($parameters[0]->getType()?->getName())->toBe(Article::class);
});

/*
|--------------------------------------------------------------------------
| download_count cannot be written any other way
|--------------------------------------------------------------------------
*/

it('ignores download_count on create', function () {
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
        'download_count' => 500,
    ]);

    expect($article->fresh()->download_count)->toBe(0);
});

it('ignores download_count on update and fill', function () {
    $article = Article::factory()->published()->create(['download_count' => 7]);

    $article->update(['download_count' => 9999, 'topic' => 'Renamed']);
    $article->fill(['download_count' => 1234])->save();

    $fresh = $article->fresh();

    expect($fresh->download_count)->toBe(7)
        // The same update did apply the column that IS fillable: the ignore is
        // specific to the counter, not the update silently doing nothing.
        ->and($fresh->topic)->toBe('Renamed');
});

it('leaves download_count out of the fillable columns', function () {
    expect((new Article)->getFillable())->not->toContain('download_count')
        ->and((new Article)->isFillable('download_count'))->toBeFalse();
});
