<?php

declare(strict_types=1);

use App\Domain\Publications\Actions\UnpublishArticleAction;
use App\Domain\Publications\Models\Article;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// Livewire state left by an earlier file's panel render would inject its script
// into the 404 pages these tests compare; PublicPagesTest explains. Flushed
// before each test.
beforeEach(function (): void {
    Livewire::flushState();
});

/*
|--------------------------------------------------------------------------
| The article download route (P4-T13)
|--------------------------------------------------------------------------
|
| The file has no URL: this route is the only reader, so what it refuses is what
| nobody can read. These tests are about that. A published article downloads, and
| the same address is a 404 the moment the article is unpublished, with the
| counter untouched; an unknown slug and an unpublished one are indistinguishable;
| a download that cannot be served is never counted; and the route has its own
| limit on top of the group's.
|
| Every expected value is written out by hand from the fixture: the bytes, the
| header text, the counts and the filenames. Nothing is read back from the
| controller's own constants.
*/

beforeEach(function () {
    Storage::fake('private');

    $this->download = fn (string $slug, string $ip = '203.0.113.7'): TestResponse => $this
        ->withServerVariables(['REMOTE_ADDR' => $ip])
        ->get('/publications/'.$slug.'/download');

    // A published article whose PDF is really on the private disk.
    $this->publishedWithFile = function (array $attributes = [], string $bytes = '%PDF-1.4 the real bytes'): Article {
        $article = Article::factory()->published()->create($attributes);
        Storage::disk('private')->put($article->path, $bytes);

        return $article;
    };
});

/**
 * The filename a Content-Disposition header carries, quotes or none.
 *
 * Symfony writes a name made only of token characters bare and quotes the rest, so
 * the test reads the value rather than assume which.
 */
function dispositionFilename(?string $header): string
{
    preg_match('/^attachment; filename="?([^";]*)"?(?:;|$)/', (string) $header, $match);

    return $match[1] ?? '';
}

/** The count the database holds for an article, read fresh. */
function downloadCountOf(Article $article): int
{
    return (int) DB::table('articles')->where('id', $article->getKey())->value('download_count');
}

it('serves a published article as a PDF download and counts it once', function () {
    $article = ($this->publishedWithFile)(['original_filename' => 'Safety Guide 2026.pdf'], '%PDF-1.4 safety guide bytes');

    $response = ($this->download)($article->slug)->assertOk();

    expect($response->streamedContent())->toBe('%PDF-1.4 safety guide bytes')
        ->and($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($response->headers->get('Content-Disposition'))->toBe('attachment; filename="Safety Guide 2026.pdf"')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and(downloadCountOf($article))->toBe(1);
});

it('answers a HEAD like a GET, with the same status and headers, but does not count it', function () {
    $article = ($this->publishedWithFile)(['original_filename' => 'Safety Guide 2026.pdf'], '%PDF-1.4 the real bytes');

    $head = $this->call('HEAD', '/publications/'.$article->slug.'/download', server: ['REMOTE_ADDR' => '203.0.113.80']);

    $head->assertOk();

    // Nothing moved: a HEAD fetches no bytes, so it is not a download.
    expect(downloadCountOf($article))->toBe(0);

    $get = ($this->download)($article->slug, '203.0.113.81')->assertOk();

    // The same answer to both, headers included, and then the GET is the one that counts.
    expect($head->headers->get('Content-Type'))->toBe('application/pdf')
        ->and($head->headers->get('Content-Type'))->toBe($get->headers->get('Content-Type'))
        ->and($head->headers->get('Content-Disposition'))->toBe('attachment; filename="Safety Guide 2026.pdf"')
        ->and($head->headers->get('Content-Disposition'))->toBe($get->headers->get('Content-Disposition'))
        ->and($head->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and(downloadCountOf($article))->toBe(1);
});

it('gives a HEAD the same 404 as a GET for an unpublished article, and counts nothing', function () {
    $unpublished = Article::factory()->create();
    Storage::disk('private')->put($unpublished->path, 'bytes');

    $this->call('HEAD', '/publications/'.$unpublished->slug.'/download')->assertNotFound();
    $this->call('HEAD', '/publications/no-such-article/download')->assertNotFound();

    expect(downloadCountOf($unpublished))->toBe(0);
});

it('still spends a limiter slot on a HEAD, so the limit cannot be dodged by sending one', function () {
    $article = ($this->publishedWithFile)();

    for ($attempt = 1; $attempt <= 20; $attempt++) {
        $this->call('HEAD', '/publications/'.$article->slug.'/download', server: ['REMOTE_ADDR' => '203.0.113.82'])->assertOk();
    }

    ($this->download)($article->slug, '203.0.113.82')->assertStatus(429);

    expect(downloadCountOf($article))->toBe(0);
});

it('adds one to the count for each download, and moves no other column', function () {
    $article = ($this->publishedWithFile)(['download_count' => 7]);
    $before = DB::table('articles')->where('id', $article->getKey())->first();

    ($this->download)($article->slug)->assertOk();
    ($this->download)($article->slug)->assertOk();

    $after = DB::table('articles')->where('id', $article->getKey())->first();

    expect((int) $after->download_count)->toBe(9)
        ->and((string) $after->updated_at)->toBe((string) $before->updated_at)
        ->and((string) $after->published_at)->toBe((string) $before->published_at);
});

it('names the saved file safely whatever the uploader called it', function (string $original, string $expected) {
    $article = ($this->publishedWithFile)(['original_filename' => $original]);

    $response = ($this->download)($article->slug)->assertOk();

    expect(dispositionFilename($response->headers->get('Content-Disposition')))->toBe($expected);
})->with([
    'a plain name' => ['report.pdf', 'report.pdf'],
    'an upper-case extension' => ['Paper.PDF', 'Paper.pdf'],
    'a traversal' => ['../../etc/passwd', 'passwd.pdf'],
    'a windows path' => ['C:\\Users\\me\\paper.pdf', 'paper.pdf'],
    // The stem is trimmed of spaces, dots and underscores, so the one the closing quote became goes.
    'quotes' => ['evil "quoted".pdf', 'evil _quoted.pdf'],
    'only characters that are replaced' => ['?*|<>:.pdf', 'article.pdf'],
    'header injection' => ["bad\r\nX-Injected: 1.pdf", 'bad_X-Injected_ 1.pdf'],
    'a different extension' => ['archive.pdf.exe', 'archive.pdf.pdf'],
    'no extension' => ['notes', 'notes.pdf'],
    'only an extension' => ['.pdf', 'article.pdf'],
    'only spaces' => ['   ', 'article.pdf'],
    'a percent sign' => ['100% done.pdf', '100_ done.pdf'],
    'surrounding dots and spaces' => [' ..guide.. .pdf', 'guide.pdf'],
    'a long name' => [str_repeat('a', 150).'.pdf', str_repeat('a', 100).'.pdf'],
]);

it('keeps the letters of an Arabic filename and encodes it for the header', function () {
    $article = ($this->publishedWithFile)(['original_filename' => 'دليل السلامة.pdf']);

    $disposition = (string) ($this->download)($article->slug)->assertOk()->headers->get('Content-Disposition');

    // The UTF-8 form carries the real name, percent-encoded by hand from the
    // letters: no letter was replaced with an underscore.
    expect($disposition)->toContain("filename*=utf-8''%D8%AF%D9%84%D9%8A%D9%84%20%D8%A7%D9%84%D8%B3%D9%84%D8%A7%D9%85%D8%A9.pdf");
});

it('answers the same address with a 404 once the article is unpublished, and counts nothing', function () {
    $this->seed(RolePermissionSeeder::class);

    $article = ($this->publishedWithFile)();

    ($this->download)($article->slug)->assertOk();
    expect(downloadCountOf($article))->toBe(1);

    // A real withdrawal, through the Action that owns it, by an actor holding the
    // one permission it needs.
    $actor = User::factory()->create(['is_active' => true]);
    $actor->givePermissionTo('unpublish_article');
    app(UnpublishArticleAction::class)->execute($actor->fresh(), $article);

    expect(Article::query()->published()->count())->toBe(0);

    ($this->download)($article->slug)->assertNotFound();

    // The file is still on the disk: it is the lookup that refuses, not a missing file.
    expect(Storage::disk('private')->exists($article->path))->toBeTrue()
        ->and(downloadCountOf($article))->toBe(1);
});

it('gives an unknown slug and an unpublished one the same status and the same body', function () {
    $unpublished = Article::factory()->create();
    Storage::disk('private')->put($unpublished->path, 'bytes that must stay private');

    $unknown = ($this->download)('no-such-article');
    $withdrawn = ($this->download)($unpublished->slug);

    $unknown->assertNotFound();
    $withdrawn->assertNotFound();

    // The control: two empty bodies would be equal too.
    expect($unknown->getContent())->not->toBe('')
        ->and($withdrawn->getContent())->toBe($unknown->getContent())
        ->and(str_contains((string) $withdrawn->getContent(), 'bytes that must stay private'))->toBeFalse()
        ->and(str_contains((string) $withdrawn->getContent(), $unpublished->slug))->toBeFalse()
        ->and($withdrawn->headers->get('Content-Type'))->toBe($unknown->headers->get('Content-Type'))
        ->and(downloadCountOf($unpublished))->toBe(0);
});

it('gives every odd slug the same 404 as an unknown one', function (string $slug) {
    $unknown = ($this->download)('no-such-article');

    $response = ($this->download)($slug);

    expect($response->getStatusCode())->toBe(404)
        ->and($response->getContent())->toBe($unknown->getContent());
})->with([
    'a null byte' => ['%00'],
    'very long' => [str_repeat('a', 2000)],
    'a dot segment' => ['..'],
    'an encoded slash' => ['a%2Fb'],
    'uppercase hex' => ['%C3%A9%C3%A8'],
]);

it('refuses a malformed path encoding with the framework 400 before any route, never a 500', function () {
    // Laravel's own ValidatePathEncoding answers every path with invalid UTF-8, so
    // the slug never reaches the lookup. The status is the same for every path and
    // says nothing about which slugs exist.
    $this->get('/publications/%FF%FE/download')->assertStatus(400);
    $this->get('/publications/%FF%FE')->assertStatus(400);
});

it('states publication in the lookup itself, so an unpublished article is never loaded', function () {
    $unpublished = Article::factory()->create();
    Storage::disk('private')->put($unpublished->path, 'bytes');

    $loaded = [];
    $statements = [];
    $recording = true;

    // `retrieved` fires for every article hydrated from the database. The counter's
    // own conditional UPDATE would also refuse an unpublished article, so the
    // response alone cannot tell a lookup that filters from one that does not.
    Article::retrieved(function (Article $article) use (&$loaded, &$recording): void {
        if ($recording) {
            $loaded[] = (string) $article->slug;
        }
    });
    DB::listen(function (QueryExecuted $query) use (&$statements, &$recording): void {
        if ($recording) {
            $statements[] = $query->sql;
        }
    });

    ($this->download)($unpublished->slug)->assertNotFound();
    $recording = false;

    $lookups = array_values(array_filter($statements, fn (string $sql): bool => str_starts_with($sql, 'select') && str_contains($sql, '`articles`')));

    expect($loaded)->toBe([])
        ->and($lookups)->toHaveCount(1)
        ->and($lookups[0])->toContain('`published_at` is not null')
        ->and($lookups[0])->toContain('`slug` = ?');
});

it('answers 404 and does not count when the file is missing from the disk', function () {
    $article = Article::factory()->published()->create();

    // No bytes were put on the disk: the row outlived its file.
    ($this->download)($article->slug)->assertNotFound();

    expect(downloadCountOf($article))->toBe(0);
});

it('answers 404 and does not count when the row names a disk other than the private one', function () {
    Storage::fake('public');

    $article = Article::factory()->published()->create(['disk' => 'public']);
    // The bytes are on BOTH disks at the row's path: on the disk the row names, and
    // on the private one the route reads from. Only the disk check can stop it, so
    // a route that trusted the path alone would serve the private copy.
    Storage::disk('public')->put($article->path, 'public disk bytes');
    Storage::disk('private')->put($article->path, 'private disk bytes');

    ($this->download)($article->slug)->assertNotFound();

    expect(downloadCountOf($article))->toBe(0);
});

it('answers 404 and does not count for a path that is not the shape the upload writes', function (string $path) {
    $article = Article::factory()->published()->create(['path' => $path]);

    // A disk on which EVERY file exists and every download would succeed, so the
    // only thing that can refuse is the path check: the private disk is shared with
    // other features, and a disk that cannot hold the path (a traversal, a newline)
    // must not be what stands between it and the internet.
    $disk = Mockery::mock(FilesystemAdapter::class);
    $disk->shouldReceive('exists')->andReturn(true);
    $disk->shouldReceive('download')->never();
    Storage::set('private', $disk);

    ($this->download)($article->slug)->assertNotFound();

    expect(downloadCountOf($article))->toBe(0);
})->with([
    'another feature\'s directory' => ['staff-certificates/01ARZ3NDEKTSV4RRFFQ69G5FAV.pdf'],
    'a subdirectory' => ['publications/sub/01ARZ3NDEKTSV4RRFFQ69G5FAV.pdf'],
    'a prefix before the directory' => ['x/publications/01ARZ3NDEKTSV4RRFFQ69G5FAV.pdf'],
    'another extension' => ['publications/01ARZ3NDEKTSV4RRFFQ69G5FAV.png'],
    'a name that is not a ULID' => ['publications/not-a-ulid.pdf'],
    'a trailing extension' => ['publications/01ARZ3NDEKTSV4RRFFQ69G5FAV.pdf.exe'],
    'a traversal' => ['publications/../staff-certificates/01ARZ3NDEKTSV4RRFFQ69G5FAV.pdf'],
    // `$` would let the trailing newline through; `\z` does not.
    'a trailing newline' => ["publications/01ARZ3NDEKTSV4RRFFQ69G5FAV.pdf\n"],
    'a leading newline' => ["\npublications/01ARZ3NDEKTSV4RRFFQ69G5FAV.pdf"],
]);

it('serves a generated ULID path in either letter case', function (string $name) {
    $article = ($this->publishedWithFile)(['path' => 'publications/'.$name.'.pdf']);

    ($this->download)($article->slug)->assertOk();

    expect(downloadCountOf($article))->toBe(1);
})->with([
    'upper case' => ['01ARZ3NDEKTSV4RRFFQ69G5FAV'],
    'lower case' => ['01arz3ndektsv4rrffq69g5fav'],
]);

it('answers 404 and does not count an article unpublished after the lookup', function () {
    $article = ($this->publishedWithFile)();

    // The race: the row is read as published, and withdrawn before the count. The
    // listener fires as the lookup hydrates the row, and the counter's own
    // conditional UPDATE then finds nothing to change.
    Article::retrieved(function (Article $loaded): void {
        DB::table('articles')->where('id', $loaded->getKey())->update(['published_at' => null]);
    });

    $response = ($this->download)($article->slug);

    $response->assertNotFound();

    expect(str_contains((string) $response->getContent(), '%PDF'))->toBeFalse()
        ->and(downloadCountOf($article))->toBe(0);
});

it('sets no cookie on a download, a refusal or a throttled request', function () {
    $article = ($this->publishedWithFile)();

    $responses = [
        'a download' => ($this->download)($article->slug, '203.0.113.70'),
        'a 404' => ($this->download)('no-such-article', '203.0.113.70'),
    ];

    for ($attempt = 1; $attempt <= 19; $attempt++) {
        ($this->download)($article->slug, '203.0.113.70');
    }

    // The 22nd request from this address in the minute: past the limit of 20.
    $responses['a throttled request'] = ($this->download)($article->slug, '203.0.113.70');
    expect($responses['a throttled request']->getStatusCode())->toBe(429);

    foreach ($responses as $label => $response) {
        expect($response->headers->getCookies())->toBe([], "{$label} set a cookie.")
            ->and($response->headers->has('Set-Cookie'))->toBeFalse("{$label} sent a Set-Cookie header.");
    }
});

it('registers the download limiter as 20 a minute keyed by the request IP', function () {
    $callback = RateLimiter::limiter('publication-download');

    expect($callback)->not->toBeNull('The publication-download limiter is not registered at all.');

    $limit = $callback(Request::create('/', 'GET', server: ['REMOTE_ADDR' => '203.0.113.9']));
    $other = $callback(Request::create('/', 'GET', server: ['REMOTE_ADDR' => '203.0.113.10']));

    expect($limit)->toBeInstanceOf(Limit::class)
        ->and($limit->maxAttempts)->toBe(20)
        ->and($limit->decaySeconds)->toBe(60)
        ->and($limit->key)->toBe('203.0.113.9')
        ->and($other->key)->toBe('203.0.113.10');
});

it('puts the download limiter on the download route, on top of the group limiter, and on no other', function () {
    $middleware = fn (string $name): array => Route::getRoutes()->getByName($name)->gatherMiddleware();

    expect($middleware('public.publications.download'))->toContain('throttle:publication-download')->toContain('throttle:public-site')
        ->and($middleware('public.publications.show'))->not->toContain('throttle:publication-download')->toContain('throttle:public-site')
        ->and($middleware('public.publications.index'))->not->toContain('throttle:publication-download')->toContain('throttle:public-site');
});

it('refuses the 21st download in a minute from one IP, and still serves another', function () {
    $article = ($this->publishedWithFile)();

    for ($attempt = 1; $attempt <= 20; $attempt++) {
        ($this->download)($article->slug, '203.0.113.50')->assertOk();
    }

    ($this->download)($article->slug, '203.0.113.50')->assertStatus(429);

    // Per IP, not global; and the refused request never reached the counter.
    ($this->download)($article->slug, '203.0.113.51')->assertOk();

    expect(downloadCountOf($article))->toBe(21);
});

it('lets the library pages through where the download is refused', function () {
    $article = ($this->publishedWithFile)();

    for ($attempt = 1; $attempt <= 21; $attempt++) {
        ($this->download)($article->slug, '203.0.113.60');
    }

    // The download limiter is the download's own: the pages stay on the group's 120.
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.60'])->get('/publications')->assertOk();
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.60'])->get('/publications/'.$article->slug)->assertOk();
});
