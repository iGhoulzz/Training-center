<?php

declare(strict_types=1);

use App\Domain\Publications\Data\PublicArticle;
use App\Domain\Publications\Models\Article;
use App\Domain\Publications\Queries\PublicArticleCatalogue;
use Dom\HTMLDocument;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Illuminate\Testing\TestResponse;
use Illuminate\View\ComponentAttributeBag;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// Livewire state left by an earlier file's panel render would inject its script
// into these pages; PublicPagesTest explains. Flushed before each test.
beforeEach(function (): void {
    Livewire::flushState();
});

/*
|--------------------------------------------------------------------------
| The publications library and the article page (P4-T13)
|--------------------------------------------------------------------------
|
| THE COPY IS A DRAFT, SO THESE TESTS DO NOT QUOTE IT. Each line a test reads is
| pointed at a sentinel for the length of that test, which proves the page prints
| what the key holds without freezing wording the owner will replace. Numbers,
| titles, orders and links are what is asserted, and each is written out by hand
| from the fixture.
|
| NOTHING UNPUBLISHED IS EVER VISIBLE, on the index, in a count, in a chip, in a
| search result, on the article page or in "more in this topic"; several tests
| below plant an unpublished article that would break each of those.
*/

beforeEach(function () {
    // Sentinels for every line the tests read. The group is loaded FIRST:
    // addLines() writes into the loaded table without reading the file, and a
    // group already marked loaded is never read, so adding one line to an unloaded
    // group would blank every other key in it.
    app('translator')->load('*', 'publications', 'en');
    app('translator')->addLines([
        'publications.public.topic_all' => 'ALLSENT',
        'publications.public.count' => ':count COUNTSENT|:count COUNTSENT',
        'publications.public.count_in_topic' => ':count INTOPICSENT :topic|:count INTOPICSENT :topic',
        'publications.public.count_matching' => ':count MATCHSENT :search|:count MATCHSENT :search',
        'publications.public.count_in_topic_matching' => ':count BOTHSENT :topic :search|:count BOTHSENT :topic :search',
        'publications.public.downloads' => ':count DLSENT|:count DLSENT',
        'publications.public.downloads_note' => 'APPROXSENT',
        'publications.public.sort_newest' => 'NEWESTSENT',
        'publications.public.sort_downloads' => 'DOWNSENT',
        'publications.public.empty_library' => 'EMPTYLIBSENT',
        'publications.public.empty_heading' => 'EMPTYHEADSENT',
        'publications.public.empty_clear' => 'CLEARSENT',
        'publications.public.download_pdf' => 'PDFSENT',
        'publications.public.pdf' => 'PDFLABELSENT',
        'publications.public.sort_switch' => 'SWITCHSENT :current TO :next',
        'publications.public.original_filing' => 'FILINGSENT',
        'publications.public.more_in' => 'MORESENT :topic',
        'publications.public.issued' => 'ISSUEDSENT :date',
        'publications.public.date_format' => 'Y/m/d',
    ], 'en');
});

/** A published article, with whatever the test needs to say about it. */
function libraryArticle(array $attributes = []): Article
{
    return Article::factory()->published()->create($attributes);
}

/**
 * The visible text of every article card on the index, in page order: the title link.
 *
 * @return list<string>
 */
function libraryCardTitles(string $html): array
{
    preg_match_all('/<article\b.*?<\/article>/s', $html, $articles);

    $titles = [];

    foreach ($articles[0] as $article) {
        preg_match('/<h2\b[^>]*>\s*<a\b[^>]*>(.*?)<\/a>/s', $article, $link);

        $titles[] = trim(html_entity_decode(strip_tags($link[1] ?? '')));
    }

    return $titles;
}

/**
 * Every link in a piece of HTML: its text with whitespace collapsed, its decoded
 * href, and the raw attribute string.
 *
 * @return list<array{text: string, href: string, attributes: string}>
 */
function libraryAnchors(string $html): array
{
    preg_match_all('/<a\b([^>]*)>(.*?)<\/a>/s', $html, $matches, PREG_SET_ORDER);

    return array_map(function (array $match): array {
        preg_match('/\bhref="([^"]*)"/', $match[1], $href);

        return [
            'text' => trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($match[2])))),
            'href' => html_entity_decode($href[1] ?? ''),
            'attributes' => $match[1],
        ];
    }, $matches);
}

/** The link with this text, or a failure naming the links there were. */
function libraryAnchor(string $html, string $text): array
{
    foreach (libraryAnchors($html) as $anchor) {
        if ($anchor['text'] === $text) {
            return $anchor;
        }
    }

    throw new RuntimeException("No link reads [{$text}]; the page has: ".implode(' | ', array_column(libraryAnchors($html), 'text')));
}

/**
 * The query string of a link, as an array sorted by key, so a test compares what
 * the link carries and not the order it happened to write it in.
 *
 * @return array<string, mixed>
 */
function libraryQueryOf(string $href): array
{
    parse_str((string) parse_url($href, PHP_URL_QUERY), $query);

    ksort($query);

    return $query;
}

/** The count line's text: the number and its sentinel, as printed. */
function libraryCountLine(string $html): ?string
{
    preg_match('/>\s*(\d+ (?:COUNTSENT|INTOPICSENT|MATCHSENT|BOTHSENT)[^<]*?)\s*</', $html, $match);

    return $match[1] ?? null;
}

/**
 * The level and text of every heading in the page's <main>, in page order. The
 * footer's own headings belong to the layout, not to the page under test.
 *
 * @return list<array{0: int, 1: string}>
 */
function libraryHeadings(string $html): array
{
    preg_match('/<main\b.*?<\/main>/s', $html, $main);
    preg_match_all('/<h([1-6])\b[^>]*>(.*?)<\/h\1>/s', $main[0] ?? '', $matches, PREG_SET_ORDER);

    return array_map(
        fn (array $match): array => [(int) $match[1], trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($match[2]))))],
        $matches,
    );
}

/** The body of a response, as a string. */
function libraryBody(TestResponse $response): string
{
    return (string) $response->getContent();
}

/*
|--------------------------------------------------------------------------
| What the index lists, and what it never does
|--------------------------------------------------------------------------
*/

it('lists only published articles, in the cards, the count and the chips', function () {
    libraryArticle(['title_en' => 'Alpha Guide', 'topic' => 'Welding', 'issued_on' => '2026-02-01']);
    libraryArticle(['title_en' => 'Beta Guide', 'topic' => 'Safety', 'issued_on' => '2026-01-01']);
    // Unpublished, in a topic of its own and with the most recent date: it must
    // not appear in the cards, the count, the chips or the "All" total.
    Article::factory()->create(['title_en' => 'Hidden Guide', 'topic' => 'Hidden Topic', 'issued_on' => '2026-03-01']);

    $html = libraryBody($this->get('/publications')->assertOk());

    expect(libraryCardTitles($html))->toBe(['Alpha Guide', 'Beta Guide'])
        ->and(libraryCountLine($html))->toBe('2 COUNTSENT')
        ->and(libraryAnchor($html, 'ALLSENT 2'))->toBeArray()
        ->and(libraryAnchor($html, 'Welding 1'))->toBeArray()
        ->and(libraryAnchor($html, 'Safety 1'))->toBeArray()
        ->and(str_contains($html, 'Hidden'))->toBeFalse();
});

it('shows the empty library, with no cards, when nothing is published', function () {
    Article::factory()->create(['title_en' => 'Hidden Guide']);

    $html = libraryBody($this->get('/publications')->assertOk());

    expect(libraryCardTitles($html))->toBe([])
        ->and(libraryCountLine($html))->toBe('0 COUNTSENT')
        ->and(str_contains($html, 'EMPTYLIBSENT'))->toBeTrue()
        ->and(str_contains($html, 'EMPTYHEADSENT'))->toBeFalse()
        ->and(libraryAnchor($html, 'ALLSENT 0'))->toBeArray();
});

it('prints each card from its article: topic, date, title link, description, authors, downloads and the PDF link', function () {
    $article = libraryArticle([
        'title_en' => 'Enforcing Foreign Awards',
        'topic' => 'Arbitration',
        'authors' => 'Dr. Salah Ferjani',
        'description_en' => 'A practitioner reading of the grounds.',
        'issued_on' => '2026-02-12',
        'download_count' => 318,
    ]);

    $html = libraryBody($this->get('/publications')->assertOk());

    preg_match('/<article\b.*?<\/article>/s', $html, $card);
    $text = trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($card[0]))));

    // Computed by hand: the date format is the sentinel 'Y/m/d', for 2026-02-12. The
    // card shows the bare date (the design's line has no room for "Issued"), and the
    // PDF link reads the short label.
    expect($text)->toBe('Arbitration 2026/02/12 Enforcing Foreign Awards A practitioner reading of the grounds. Dr. Salah Ferjani PDFLABELSENT 318 DLSENT');

    $links = libraryAnchors($card[0]);

    expect(array_column($links, 'href'))->toBe([
        url('/publications/'.$article->slug),
        url('/publications/'.$article->slug.'/download'),
    ])->and(str_contains($html, '<time datetime="2026-02-12"'))->toBeTrue();
});

it('renders the topic and issue date on the card and on the article page from one component', function () {
    $article = libraryArticle(['topic' => 'Arbitration', 'issued_on' => '2026-02-12']);

    $fragment = '/<p data-article-meta\b[^>]*>.*?<\/p>/s';

    preg_match_all($fragment, libraryBody($this->get('/publications')->assertOk()), $onCard);
    preg_match_all($fragment, libraryBody($this->get('/publications/'.$article->slug)->assertOk()), $onPage);

    // One on each page. Both hold the same badge and the same <time> element; they
    // differ only in the design's two ways: the card says the bare date, the article
    // says "Issued ...", and the article's header is the dark variant. Written by hand:
    // the sentinel date format 'Y/m/d' and the sentinel wording 'ISSUEDSENT :date'.
    expect($onCard[0])->toHaveCount(1)
        ->and($onPage[0])->toHaveCount(1)
        ->and(trim((string) preg_replace('/\s+/', ' ', strip_tags($onCard[0][0]))))->toBe('Arbitration 2026/02/12')
        ->and(trim((string) preg_replace('/\s+/', ' ', strip_tags($onPage[0][0]))))->toBe('Arbitration ISSUEDSENT 2026/02/12')
        ->and($onCard[0][0])->toContain('>Arbitration</span>')->toContain('<time datetime="2026-02-12"')
        ->and($onPage[0][0])->toContain('>Arbitration</span>')->toContain('<time datetime="2026-02-12"');
});

it('names the title in the download link for a screen reader, whatever the visible text', function () {
    libraryArticle(['title_en' => 'Safety Guide']);

    $html = libraryBody($this->get('/publications')->assertOk());

    // The accessible name contains the visible words and adds the title, so a list of
    // twelve identical "PDF" links is not twelve identical names.
    expect(libraryAnchor($html, 'PDFLABELSENT')['attributes'])->toContain('aria-label="Download PDF: Safety Guide"');
});

it('says that download counts are approximate, on the index and on an article page', function () {
    $article = libraryArticle();

    foreach (['/publications', '/publications/'.$article->slug] as $path) {
        expect(substr_count(libraryBody($this->get($path)->assertOk()), 'APPROXSENT'))->toBe(1, $path);
    }
});

it('never prints where a file lives', function () {
    $article = libraryArticle(['original_filename' => 'secret-original-name.pdf']);

    foreach (['/publications', '/publications/'.$article->slug] as $path) {
        $html = libraryBody($this->get($path)->assertOk());

        // The stored file name is the ULID and ".pdf" after the directory, so it is
        // looked for on its own as well as inside the whole path.
        foreach (['secret-original-name', $article->path, substr($article->path, 13), 'private'] as $needle) {
            expect(str_contains($html, $needle))->toBeFalse("{$path} contains [{$needle}].");
        }
    }
});

/*
|--------------------------------------------------------------------------
| Search
|--------------------------------------------------------------------------
*/

it('matches a search on each searchable field, and on nothing unpublished', function (string $column, string $needle) {
    $target = libraryArticle(['title_en' => 'Target for '.$column, $column => $needle]);

    // Two decoys that do not hold the sentinel, and an unpublished article that does.
    libraryArticle(['title_en' => 'First decoy']);
    libraryArticle(['title_en' => 'Second decoy']);
    Article::factory()->create(['title_en' => 'Hidden holder', $column => $needle]);

    // A fragment from the middle of the value, so the match is a contains, not an equals.
    $fragment = mb_substr($needle, 2, mb_strlen($needle) - 4);

    $html = libraryBody($this->get('/publications?'.http_build_query(['q' => $fragment]))->assertOk());

    expect(libraryCardTitles($html))->toBe([$column === 'title_en' ? $needle : 'Target for '.$column])
        ->and(libraryCountLine($html))->toBe('1 MATCHSENT '.$fragment)
        ->and($target->isPublished())->toBeTrue();
})->with([
    'the English title' => ['title_en', 'xxzqxtitleenxx'],
    'the Arabic title' => ['title_ar', 'xxعنوانفريدxx'],
    'the English description' => ['description_en', 'xxzqxdescriptionenxx'],
    'the Arabic description' => ['description_ar', 'xxوصففريدxx'],
    'the topic' => ['topic', 'xxzqxtopicxx'],
    'the authors' => ['authors', 'xxzqxauthorsxx'],
]);

it('matches %, _ and a backslash in a search as themselves, never as patterns', function (string $needle, string $literal, string $lookalike) {
    libraryArticle(['title_en' => $literal]);
    libraryArticle(['title_en' => $lookalike]);

    $html = libraryBody($this->get('/publications?'.http_build_query(['q' => $needle]))->assertOk());

    // Unescaped, "50%" would match both titles, "a_b" both, and "Back\slash" would
    // read its backslash as an escape and find only the lookalike.
    expect(libraryCardTitles($html))->toBe([$literal]);
})->with([
    'a percent sign' => ['50%', '50% Off Safety', '500 Off Safety'],
    'an underscore' => ['a_b', 'Alpha a_b Guide', 'Alpha axb Guide'],
    'a backslash' => ['Back\\slash', 'Back\\slash Guide', 'Backslash Guide'],
]);

it('finds nothing for a search that matches nothing, and says so with a way out', function () {
    libraryArticle(['title_en' => 'Safety Guide']);

    $html = libraryBody($this->get('/publications?q=zzzz')->assertOk());

    expect(libraryCardTitles($html))->toBe([])
        ->and(libraryCountLine($html))->toBe('0 MATCHSENT zzzz')
        ->and(str_contains($html, 'EMPTYHEADSENT'))->toBeTrue()
        // The way out is the unfiltered library, with no query at all.
        ->and(libraryAnchor($html, 'CLEARSENT')['href'])->toBe(url('/publications'));
});

it('trims the search, cuts it at 100 characters and prints what it used', function () {
    libraryArticle(['title_en' => 'Safety Guide']);

    $long = str_repeat('x', 150);

    $trimmed = libraryBody($this->get('/publications?'.http_build_query(['q' => "   safety  \t"]))->assertOk());
    $capped = libraryBody($this->get('/publications?'.http_build_query(['q' => $long]))->assertOk());

    expect($trimmed)->toContain('name="q"')->toContain('value="safety"')
        ->and(libraryCardTitles($trimmed))->toBe(['Safety Guide'])
        ->and($capped)->toContain('value="'.str_repeat('x', 100).'"')
        ->and(str_contains($capped, str_repeat('x', 101)))->toBeFalse();
});

it('reads a search, topic or sort that is not text as no filter, and invalid UTF-8 as text', function (string $query) {
    libraryArticle(['title_en' => 'Safety Guide']);

    $html = libraryBody($this->get('/publications?'.$query)->assertOk());

    expect(libraryCardTitles($html))->toBe(['Safety Guide']);
})->with([
    'an array search' => ['q[]=x&q[]=y'],
    'an array topic' => ['topic[]=x'],
    'an array sort' => ['sort[]=downloads'],
    'a keyed array' => ['q[a][b]=x'],
    'an empty search' => ['q='],
    'only spaces' => ['q=%20%20'],
    'an unknown sort' => ['sort=oldest'],
]);

it('does not fail on invalid UTF-8 in the query string', function (string $query) {
    libraryArticle(['title_en' => 'Safety Guide']);

    $this->get('/publications?'.$query)->assertOk();
})->with([
    'the search' => ['q=%FF%FE'],
    'the topic' => ['topic=%FF%FE'],
    'the sort' => ['sort=%FF'],
]);

it('escapes what the visitor typed, in the box and in the count line', function () {
    libraryArticle();

    $html = libraryBody($this->get('/publications?'.http_build_query(['q' => '"><script>alert(1)</script>']))->assertOk());

    expect(str_contains(strtolower($html), '<script'))->toBeFalse()
        ->and($html)->toContain('value="&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;"');
});

/*
|--------------------------------------------------------------------------
| The topic filter, the chips and the sort
|--------------------------------------------------------------------------
*/

it('filters by topic with an exact match, and marks the chip', function () {
    libraryArticle(['title_en' => 'Weld One', 'topic' => 'Welding', 'issued_on' => '2026-02-01']);
    libraryArticle(['title_en' => 'Weld Two', 'topic' => 'Welding', 'issued_on' => '2026-01-01']);
    libraryArticle(['title_en' => 'Safe One', 'topic' => 'Safety']);
    libraryArticle(['title_en' => 'Weld Partial', 'topic' => 'Welding Advanced']);

    $html = libraryBody($this->get('/publications?topic=Welding')->assertOk());

    expect(libraryCardTitles($html))->toBe(['Weld One', 'Weld Two'])
        ->and(libraryCountLine($html))->toBe('2 INTOPICSENT Welding')
        ->and(libraryAnchor($html, 'Welding 2')['attributes'])->toContain('aria-current="true"')
        ->and(libraryAnchor($html, 'Safety 1')['attributes'])->not->toContain('aria-current')
        ->and(libraryAnchor($html, 'ALLSENT 4')['attributes'])->not->toContain('aria-current');

    // The chips count the whole library, not the current filter.
    $prefix = libraryBody($this->get('/publications?topic=Weld')->assertOk());

    expect(libraryCardTitles($prefix))->toBe([]);
});

it('filters by topic and search together', function () {
    // One matches both, one only the search (another topic), one only the topic.
    libraryArticle(['title_en' => 'Alpha Arc', 'topic' => 'Welding']);
    libraryArticle(['title_en' => 'Alpha Rules', 'topic' => 'Safety']);
    libraryArticle(['title_en' => 'Gamma Cut', 'topic' => 'Welding']);

    $html = libraryBody($this->get('/publications?'.http_build_query(['topic' => 'Welding', 'q' => 'alpha']))->assertOk());

    expect(libraryCardTitles($html))->toBe(['Alpha Arc'])
        ->and(libraryCountLine($html))->toBe('1 BOTHSENT Welding alpha');
});

it('counts the chips from published articles only, one per topic, alphabetically', function () {
    libraryArticle(['topic' => 'Welding']);
    libraryArticle(['topic' => 'Welding']);
    libraryArticle(['topic' => 'Safety']);
    libraryArticle(['topic' => 'Electronics']);
    Article::factory()->count(3)->create(['topic' => 'Welding']);
    Article::factory()->create(['topic' => 'Only Unpublished']);

    $html = libraryBody($this->get('/publications')->assertOk());

    // The chips are the links that end in a count; no other link on the page does.
    $chips = array_column(libraryAnchors($html), 'text');

    expect(array_values(array_filter($chips, fn (string $text): bool => preg_match('/ \d+$/', $text) === 1)))
        ->toBe(['ALLSENT 4', 'Electronics 1', 'Safety 1', 'Welding 2']);
});

it('sets the chip count back far enough to be read: 74% on a white chip, the design\'s 55% on the selected one', function () {
    libraryArticle(['topic' => 'Welding']);
    libraryArticle(['topic' => 'Safety']);

    $html = libraryBody($this->get('/publications?topic=Welding')->assertOk());

    // The design draws 55% on every chip. On the white chip that is 2.86:1 against the
    // 4.5:1 a small number needs (the body colour at 55% over white), and 74% is the
    // lowest whole step that reaches it (4.57:1); the selected navy chip keeps 55%,
    // which is 5.19:1 (the contrast table in the task report).
    expect(libraryAnchor($html, 'Welding 1')['attributes'])->toContain('aria-current="true"');

    preg_match_all('/<a\b[^>]*>([^<]*) <span class="(opacity-\d+)">(\d+)<\/span><\/a>/', $html, $chips, PREG_SET_ORDER);

    $byLabel = array_column(array_map(fn (array $chip): array => [trim($chip[1]), $chip[2]], $chips), 1, 0);

    expect($byLabel)->toBe(['ALLSENT' => 'opacity-74', 'Safety' => 'opacity-74', 'Welding' => 'opacity-55']);
});

it('keeps the search and the sort on every chip, and the search and the topic on the sort button', function () {
    libraryArticle(['title_en' => 'Weld Guide', 'topic' => 'Welding']);
    libraryArticle(['title_en' => 'Safe Guide', 'topic' => 'Safety']);
    libraryArticle(['title_en' => 'Other', 'topic' => 'Safety']);

    $html = libraryBody($this->get('/publications?'.http_build_query(['q' => 'guide', 'topic' => 'Safety', 'sort' => 'downloads']))->assertOk());

    // Written in key order, which is how libraryQueryOf() returns them.
    expect(libraryQueryOf(libraryAnchor($html, 'ALLSENT 3')['href']))->toBe(['q' => 'guide', 'sort' => 'downloads'])
        ->and(libraryQueryOf(libraryAnchor($html, 'Safety 2')['href']))->toBe(['q' => 'guide', 'sort' => 'downloads', 'topic' => 'Safety'])
        ->and(libraryQueryOf(libraryAnchor($html, 'Welding 1')['href']))->toBe(['q' => 'guide', 'sort' => 'downloads', 'topic' => 'Welding'])
        // The sort is ONE button: it reads the order the list is in now (downloads) and
        // links to the other one (newest), keeping the search and the topic.
        ->and(libraryQueryOf(libraryAnchor($html, 'DOWNSENT')['href']))->toBe(['q' => 'guide', 'sort' => 'newest', 'topic' => 'Safety'])
        ->and(libraryAnchor($html, 'DOWNSENT')['attributes'])->toContain('aria-label="SWITCHSENT DOWNSENT TO NEWESTSENT"')
        ->and(array_column(libraryAnchors($html), 'text'))->not->toContain('NEWESTSENT');
});

it('keeps a search for "0" on the links, which a falsy filter would drop', function () {
    libraryArticle(['title_en' => 'Part 0 Guide']);

    $html = libraryBody($this->get('/publications?q=0')->assertOk());

    expect(libraryCardTitles($html))->toBe(['Part 0 Guide'])
        ->and(libraryQueryOf(libraryAnchor($html, 'ALLSENT 1')['href']))->toBe(['q' => '0', 'sort' => 'newest']);
});

it('has no query at all on the clear link and the unfiltered chips, apart from the sort', function () {
    libraryArticle(['topic' => 'Welding']);

    $html = libraryBody($this->get('/publications')->assertOk());

    expect(libraryQueryOf(libraryAnchor($html, 'ALLSENT 1')['href']))->toBe(['sort' => 'newest'])
        ->and(libraryQueryOf(libraryAnchor($html, 'Welding 1')['href']))->toBe(['sort' => 'newest', 'topic' => 'Welding'])
        // The sort button names the current order (newest) and links to the other one.
        ->and(libraryQueryOf(libraryAnchor($html, 'NEWESTSENT')['href']))->toBe(['sort' => 'downloads']);
});

/**
 * Six articles with every tie the two sorts break, and their titles by name.
 *
 *   title  issued_on   published_at       downloads
 *   B      2026-03-01  2026-04-01 10:00         5   (created FIRST, so its id is lower than A's)
 *   A      2026-01-10  2026-02-01 09:00         5   (older than B but created after it: ties B on downloads only)
 *   C      2025-12-01  2026-01-05 09:00        90
 *   D      2026-03-01  2026-04-02 10:00         1
 *   E      2026-03-01  2026-04-02 10:00         1   (created after D: ties D on everything but id)
 *   F      2026-03-01  2026-04-02 10:00         1   (created after E)
 *
 * B is created before A on purpose: the issue date and the id then disagree about
 * which of the two downloads-tied articles comes first, so a sort that dropped the
 * issue date and fell straight to the id would put A first and fail.
 */
function librarySortFixture(): void
{
    libraryArticle(['title_en' => 'B', 'issued_on' => '2026-03-01', 'published_at' => '2026-04-01 10:00:00', 'download_count' => 5]);
    libraryArticle(['title_en' => 'A', 'issued_on' => '2026-01-10', 'published_at' => '2026-02-01 09:00:00', 'download_count' => 5]);
    libraryArticle(['title_en' => 'C', 'issued_on' => '2025-12-01', 'published_at' => '2026-01-05 09:00:00', 'download_count' => 90]);
    libraryArticle(['title_en' => 'D', 'issued_on' => '2026-03-01', 'published_at' => '2026-04-02 10:00:00', 'download_count' => 1]);
    libraryArticle(['title_en' => 'E', 'issued_on' => '2026-03-01', 'published_at' => '2026-04-02 10:00:00', 'download_count' => 1]);
    libraryArticle(['title_en' => 'F', 'issued_on' => '2026-03-01', 'published_at' => '2026-04-02 10:00:00', 'download_count' => 1]);
}

it('sorts newest first by issue date, then publication time, then id', function () {
    librarySortFixture();

    // Hand-written. The 2026-03-01 group is B, D, E, F: B was published earliest so
    // it comes last of them; D, E and F tie on both dates, so the higher id is first.
    expect(libraryCardTitles(libraryBody($this->get('/publications')->assertOk())))->toBe(['F', 'E', 'D', 'B', 'A', 'C'])
        ->and(libraryCardTitles(libraryBody($this->get('/publications?sort=newest')->assertOk())))->toBe(['F', 'E', 'D', 'B', 'A', 'C']);
});

it('sorts most downloaded first, then newest issue date, then id', function () {
    librarySortFixture();

    // C has 90. A and B tie on 5, and B was issued later (though A has the higher
    // id, so only the issue date can put B first). D, E and F tie on 1 and on every
    // date, so the higher id is first.
    expect(libraryCardTitles(libraryBody($this->get('/publications?sort=downloads')->assertOk())))->toBe(['C', 'B', 'A', 'F', 'E', 'D']);
});

it('falls back to newest first for any other sort', function (string $sort) {
    librarySortFixture();

    $html = libraryBody($this->get('/publications?'.http_build_query(['sort' => $sort]))->assertOk());

    // The sort button reads the order in force, which is newest first, and links to
    // the other one; "most downloaded" is not offered as the current order.
    expect(libraryCardTitles($html))->toBe(['F', 'E', 'D', 'B', 'A', 'C'])
        ->and(libraryQueryOf(libraryAnchor($html, 'NEWESTSENT')['href']))->toBe(['sort' => 'downloads'])
        ->and(array_column(libraryAnchors($html), 'text'))->not->toContain('DOWNSENT');
})->with(['an unknown word' => ['oldest'], 'a different case' => ['DOWNLOADS'], 'a number' => ['1'], 'empty' => ['']]);

it('keeps a topic and a search when the sort changes the order', function () {
    libraryArticle(['title_en' => 'Weld Low', 'topic' => 'Welding', 'issued_on' => '2026-03-01', 'download_count' => 1]);
    libraryArticle(['title_en' => 'Weld High', 'topic' => 'Welding', 'issued_on' => '2026-01-01', 'download_count' => 50]);
    libraryArticle(['title_en' => 'Safe Top', 'topic' => 'Safety', 'issued_on' => '2026-04-01', 'download_count' => 99]);

    expect(libraryCardTitles(libraryBody($this->get('/publications?topic=Welding&q=weld&sort=downloads')->assertOk())))->toBe(['Weld High', 'Weld Low'])
        ->and(libraryCardTitles(libraryBody($this->get('/publications?topic=Welding&q=weld&sort=newest')->assertOk())))->toBe(['Weld Low', 'Weld High']);
});

/*
|--------------------------------------------------------------------------
| Pagination
|--------------------------------------------------------------------------
*/

it('pages the library 12 at a time and keeps the search, topic and sort on the page links', function () {
    // Thirteen "Guide" articles in one topic, newest first by an issue date that
    // rises with the number: Guide 13 is newest, Guide 01 the oldest.
    foreach (range(1, 13) as $number) {
        libraryArticle([
            'title_en' => sprintf('Guide %02d', $number),
            'topic' => 'Safety',
            'issued_on' => sprintf('2026-01-%02d', $number),
        ]);
    }

    $first = libraryBody($this->get('/publications?'.http_build_query(['q' => 'guide', 'topic' => 'Safety', 'sort' => 'downloads']))->assertOk());
    $second = libraryBody($this->get('/publications?'.http_build_query(['q' => 'guide', 'topic' => 'Safety', 'sort' => 'downloads', 'page' => 2]))->assertOk());

    // Every count ties at 0, so "most downloaded" falls back to newest.
    expect(libraryCardTitles($first))->toHaveCount(12)
        ->and(libraryCardTitles($first)[0])->toBe('Guide 13')
        ->and(libraryCardTitles($first)[11])->toBe('Guide 02')
        ->and(libraryCardTitles($second))->toBe(['Guide 01'])
        ->and(libraryCountLine($first))->toBe('13 BOTHSENT Safety guide');

    $next = array_values(array_filter(libraryAnchors($first), fn (array $anchor): bool => str_contains($anchor['attributes'], 'rel="next"')));
    $previous = array_values(array_filter(libraryAnchors($second), fn (array $anchor): bool => str_contains($anchor['attributes'], 'rel="prev"')));

    expect($next)->toHaveCount(1)
        ->and(libraryQueryOf($next[0]['href']))->toBe(['page' => '2', 'q' => 'guide', 'sort' => 'downloads', 'topic' => 'Safety'])
        ->and(str_starts_with($next[0]['href'], url('/publications').'?'))->toBeTrue()
        ->and($previous)->toHaveCount(1)
        ->and(libraryQueryOf($previous[0]['href']))->toBe(['page' => '1', 'q' => 'guide', 'sort' => 'downloads', 'topic' => 'Safety'])
        // No previous link on the first page, no next on the last.
        ->and(str_contains($first, 'rel="prev"'))->toBeFalse()
        ->and(str_contains($second, 'rel="next"'))->toBeFalse();
});

it('shows no page links when everything fits on one page', function () {
    libraryArticle();

    $html = libraryBody($this->get('/publications')->assertOk());

    expect(str_contains($html, 'rel="next"'))->toBeFalse()
        ->and(str_contains($html, 'rel="prev"'))->toBeFalse();
});

it('shows a page past the end as no cards, and neither as an empty library nor as an empty search', function () {
    libraryArticle(['title_en' => 'Only One']);

    $html = libraryBody($this->get('/publications?page=99')->assertOk());

    expect(libraryCardTitles($html))->toBe([])
        ->and(libraryCountLine($html))->toBe('1 COUNTSENT')
        ->and(str_contains($html, 'EMPTYLIBSENT'))->toBeFalse()
        ->and(str_contains($html, 'EMPTYHEADSENT'))->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| The search form
|--------------------------------------------------------------------------
*/

it('holds exactly one form, a GET form to the library with a labelled search box and no token', function () {
    libraryArticle();

    $html = libraryBody($this->get('/publications')->assertOk());

    preg_match_all('/<form\b[^>]*>/i', $html, $forms);
    preg_match('/<form\b.*?<\/form>/s', $html, $form);

    expect($forms[0])->toHaveCount(1)
        ->and($forms[0][0])->toContain('method="get"')
        ->and($forms[0][0])->toContain('action="'.url('/publications').'"')
        ->and($form[0])->toContain('<label for="publications-search"')
        ->and($form[0])->toContain('id="publications-search"')
        ->and($form[0])->toContain('type="search"')
        ->and($form[0])->toContain('name="q"')
        ->and($form[0])->toContain('type="submit"')
        // No topic chosen: only the search and the sort travel.
        ->and(str_contains($form[0], 'name="topic"'))->toBeFalse()
        ->and($form[0])->toContain('<input type="hidden" name="sort" value="newest">');

    $lower = strtolower($html);

    foreach (['_token', 'csrf', '<script'] as $needle) {
        expect(str_contains($lower, $needle))->toBeFalse("The index contains [{$needle}].");
    }
});

it('carries the chosen topic and sort as hidden inputs, and the typed search as the box value', function () {
    libraryArticle(['topic' => 'Safety']);

    $html = libraryBody($this->get('/publications?'.http_build_query(['q' => 'guide', 'topic' => 'Safety', 'sort' => 'downloads']))->assertOk());

    preg_match('/<form\b.*?<\/form>/s', $html, $form);

    expect($form[0])->toContain('<input type="hidden" name="topic" value="Safety">')
        ->and($form[0])->toContain('<input type="hidden" name="sort" value="downloads">')
        ->and($form[0])->toContain('value="guide"');
});

it('submits to the library itself and nowhere else, whichever filters are active', function () {
    libraryArticle();

    foreach (['', '?q=x', '?topic=Safety&sort=downloads&page=2'] as $query) {
        preg_match('/<form\b[^>]*>/i', libraryBody($this->get('/publications'.$query)->assertOk()), $form);

        expect($form[0])->toContain('action="'.url('/publications').'"');
    }
});

/*
|--------------------------------------------------------------------------
| The article page
|--------------------------------------------------------------------------
*/

it('shows a published article: topic, issue date, title, authors, downloads, the PDF link and the description', function () {
    $article = libraryArticle([
        'title_en' => 'Enforcing Foreign Awards',
        'topic' => 'Arbitration',
        'authors' => 'Dr. Salah Ferjani',
        'description_en' => "First paragraph.\n\nSecond paragraph.",
        'issued_on' => '2026-02-12',
        'download_count' => 318,
    ]);

    $html = libraryBody($this->get('/publications/'.$article->slug)->assertOk());

    expect(libraryHeadings($html))->toBe([[1, 'Enforcing Foreign Awards'], [2, 'FILINGSENT']])
        ->and($html)->toContain('>Arbitration</span>')
        ->and($html)->toContain('<time datetime="2026-02-12"')
        ->and($html)->toContain('ISSUEDSENT 2026/02/12')
        ->and($html)->toContain('Dr. Salah Ferjani')
        ->and($html)->toContain('318 DLSENT')
        ->and($html)->toContain('APPROXSENT')
        // The newline survives as a newline in the markup, and the style keeps it as a break.
        ->and($html)->toContain("First paragraph.\n\nSecond paragraph.")
        ->and($html)->toContain('whitespace-pre-line')
        ->and(libraryAnchor($html, 'PDFSENT')['href'])->toBe(url('/publications/'.$article->slug.'/download'))
        ->and(str_contains($html, 'MORESENT'))->toBeFalse();
});

it('prints the article page as the design draws it: a dark header, the filing record, the lead and then the full-text note', function () {
    app('translator')->addLines([
        'publications.public.uploaded_by' => 'UPLOADSENT',
        'publications.public.full_text_note' => 'FULLTEXTSENT',
        'publications.public.back' => 'BACKSENT',
    ], 'en');

    $article = libraryArticle(['title_en' => 'Dark Header Paper', 'topic' => 'Arbitration', 'description_en' => 'The lead paragraph.', 'download_count' => 318]);
    libraryArticle(['topic' => 'Arbitration', 'issued_on' => '2020-01-01']);

    $html = libraryBody($this->get('/publications/'.$article->slug)->assertOk());
    $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);

    // The topic, the date and the title are in the navy header; the back link leads to the library.
    $band = $document->querySelector('h1')->closest('section');

    expect(explode(' ', $band->getAttribute('class')))->toContain('bg-navy')
        ->and($band->querySelector('[data-article-meta] span')->textContent)->toBe('Arbitration')
        ->and(explode(' ', $band->querySelector('[data-article-meta] span')->getAttribute('class')))->toContain('rounded-pill')
        ->and(libraryAnchor($html, 'BACKSENT')['href'])->toBe(url('/publications'));

    // Under it, in order: the filing record, the description as the lead, the full-text
    // note, and the related entries. The record says the count, the approximate note and who
    // uploaded it, and no size.
    $positions = array_map(fn (string $needle): int|false => strpos($html, $needle), ['FILINGSENT', '318 DLSENT', 'APPROXSENT', 'UPLOADSENT', 'The lead paragraph.', 'FULLTEXTSENT', 'MORESENT']);

    expect($positions)->each->not->toBeFalse()
        ->and($positions)->toBe(collect($positions)->sort()->values()->all())
        ->and(preg_match('/\b[KMG]B\b|bytes/', $html))->toBe(0);
});

it('opens the library with the back link, the eyebrow and a search box that needs no visible button', function () {
    app('translator')->addLines([
        'publications.public.back_home' => 'HOMEBACKSENT',
        'publications.public.library_eyebrow' => 'EYEBROWSENT',
    ], 'en');

    libraryArticle();

    $html = libraryBody($this->get('/publications')->assertOk());
    $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);

    $band = $document->querySelector('h1')->closest('section');

    expect(libraryAnchor($html, 'HOMEBACKSENT')['href'])->toBe(url('/'))
        ->and($band->textContent)->toContain('EYEBROWSENT')
        ->and(count($document->querySelectorAll('h1')))->toBe(1);

    // The design draws no search button: Enter submits. The button is for the keyboard, so
    // it is hidden until it has focus; and the input is 16px at every width.
    $button = $document->querySelector('form button[type="submit"]');
    $input = $document->querySelector('#publications-search');

    expect(explode(' ', $button->getAttribute('class')))->toContain('sr-only')->toContain('focus:not-sr-only')
        ->and(explode(' ', $input->getAttribute('class')))->toContain('text-base');
});

it('escapes everything an editor typed, on the cards and on the page', function () {
    $article = libraryArticle([
        'title_en' => '<script>alert("title")</script>',
        'authors' => '<img src=x onerror=alert(1)>',
        'description_en' => '<b>bold</b>',
    ]);

    foreach (['/publications', '/publications/'.$article->slug] as $path) {
        $html = libraryBody($this->get($path)->assertOk());

        foreach (['<script>alert', '<img src=x', '<b>bold'] as $needle) {
            expect(str_contains($html, $needle))->toBeFalse("{$path} contains [{$needle}] unescaped.");
        }
    }
});

it('offers the three newest other published articles in the same topic, and never itself, an unpublished one or another topic', function () {
    $current = libraryArticle(['title_en' => 'Current', 'topic' => 'Arbitration', 'issued_on' => '2026-02-12']);
    libraryArticle(['title_en' => 'P Newest', 'topic' => 'Arbitration', 'issued_on' => '2026-02-10']);
    libraryArticle(['title_en' => 'Q Second', 'topic' => 'Arbitration', 'issued_on' => '2026-01-05']);
    libraryArticle(['title_en' => 'R Third', 'topic' => 'Arbitration', 'issued_on' => '2025-12-02']);
    libraryArticle(['title_en' => 'S Fourth', 'topic' => 'Arbitration', 'issued_on' => '2025-06-01']);
    // Newer than the current article and than everything above, but unpublished.
    Article::factory()->create(['title_en' => 'U Unpublished', 'topic' => 'Arbitration', 'issued_on' => '2026-02-11']);
    libraryArticle(['title_en' => 'O Other topic', 'topic' => 'Procedure', 'issued_on' => '2026-02-13']);

    $html = libraryBody($this->get('/publications/'.$current->slug)->assertOk());

    preg_match('/<section aria-labelledby="article-more-heading".*?<\/section>/s', $html, $section);

    $titles = array_column(libraryAnchors($section[0]), 'text');

    // Each entry is a link whose text is the title and then its date; the date's
    // format is the sentinel 'Y/m/d'.
    expect($titles)->toBe(['P Newest 2026/02/10', 'Q Second 2026/01/05', 'R Third 2025/12/02'])
        ->and(libraryHeadings($html))->toBe([[1, 'Current'], [2, 'FILINGSENT'], [2, 'MORESENT Arbitration']]);
});

it('links each "more in" entry to its own page', function () {
    $current = libraryArticle(['topic' => 'Arbitration', 'issued_on' => '2026-02-12']);
    $other = libraryArticle(['topic' => 'Arbitration', 'issued_on' => '2026-02-10']);

    $html = libraryBody($this->get('/publications/'.$current->slug)->assertOk());

    preg_match('/<section aria-labelledby="article-more-heading".*?<\/section>/s', $html, $section);

    expect(array_column(libraryAnchors($section[0]), 'href'))->toBe([url('/publications/'.$other->slug)]);
});

it('answers an unknown slug and an unpublished one with the same 404', function () {
    $unpublished = Article::factory()->create(['title_en' => 'Withdrawn Paper']);

    $unknown = $this->get('/publications/no-such-article');
    $withdrawn = $this->get('/publications/'.$unpublished->slug);

    $unknown->assertNotFound();
    $withdrawn->assertNotFound();

    // The control: two empty bodies would be equal too.
    expect(libraryBody($unknown))->not->toBe('')
        ->and(libraryBody($withdrawn))->toBe(libraryBody($unknown))
        ->and(str_contains(libraryBody($withdrawn), 'Withdrawn Paper'))->toBeFalse();
});

it('stops showing an article the moment it is unpublished, on the page and in the library', function () {
    $article = libraryArticle(['title_en' => 'Short Lived']);

    $this->get('/publications/'.$article->slug)->assertOk();
    expect(libraryCardTitles(libraryBody($this->get('/publications')->assertOk())))->toBe(['Short Lived']);

    DB::table('articles')->where('id', $article->getKey())->update(['published_at' => null]);

    $this->get('/publications/'.$article->slug)->assertNotFound();
    expect(libraryCardTitles(libraryBody($this->get('/publications')->assertOk())))->toBe([])
        ->and(libraryAnchor(libraryBody($this->get('/publications')), 'ALLSENT 0'))->toBeArray();
});

it('gives a malformed path the framework 400 on the article page, never a 500', function () {
    $this->get('/publications/%FF%FE')->assertStatus(400);
});

/*
|--------------------------------------------------------------------------
| The projection, and what views are handed
|--------------------------------------------------------------------------
*/

it('pins PublicArticle to exactly the values the pages show', function () {
    $class = new ReflectionClass(PublicArticle::class);

    $properties = [];

    foreach ($class->getProperties() as $property) {
        $properties[$property->getName()] = (string) $property->getType();
    }

    // The closure is the guarantee: adding a column to `articles` adds nothing
    // here, and showing one takes a deliberate new line in this list.
    expect($properties)->toBe([
        'slug' => 'string',
        'title' => 'string',
        'description' => 'string',
        'topic' => 'string',
        'authors' => 'string',
        'issuedOn' => 'string',
        'downloadCount' => 'int',
    ])->and($class->isFinal())->toBeTrue()
        ->and($class->isReadOnly())->toBeTrue();
});

it('projects an article into its fields, in the reader\'s language, and nothing else', function () {
    $article = libraryArticle([
        'title_en' => 'English title',
        'title_ar' => 'عنوان عربي',
        'description_en' => 'English description',
        'description_ar' => 'وصف عربي',
        'topic' => 'Arbitration',
        'authors' => 'Dr. Salah Ferjani',
        'issued_on' => '2026-02-12',
        'download_count' => 318,
        'original_filename' => 'secret.pdf',
    ]);
    $untranslated = libraryArticle(['title_en' => 'Only English', 'description_en' => 'Only English text', 'issued_on' => '2025-01-01']);

    $catalogue = app(PublicArticleCatalogue::class);

    $english = $catalogue->find($article->slug);

    expect($english)->toEqual(new PublicArticle($article->slug, 'English title', 'English description', 'Arbitration', 'Dr. Salah Ferjani', '2026-02-12', 318));

    app()->setLocale('ar');

    // Arabic where there is a translation; the English text where there is not.
    expect($catalogue->find($article->slug))->toEqual(new PublicArticle($article->slug, 'عنوان عربي', 'وصف عربي', 'Arbitration', 'Dr. Salah Ferjani', '2026-02-12', 318))
        ->and($catalogue->find($untranslated->slug)?->title)->toBe('Only English')
        ->and($catalogue->find($untranslated->slug)?->description)->toBe('Only English text');
});

it('finds only published articles, and the same null for an unknown slug', function () {
    $unpublished = Article::factory()->create();
    $catalogue = app(PublicArticleCatalogue::class);

    expect($catalogue->find($unpublished->slug))->toBeNull()
        ->and($catalogue->find('no-such-article'))->toBeNull();
});

/**
 * Every Eloquent model reachable from a value, as path => class.
 *
 * Walks arrays, the public properties of objects and anything iterable, and
 * remembers the objects it has seen so a cycle ends. A named function with samples
 * below, so it cannot quietly narrow.
 *
 * @param  array<int, bool>  $seen
 * @return array<string, string>
 */
function libraryModelsWithin(mixed $value, string $path = 'data', array &$seen = []): array
{
    if ($value instanceof Model) {
        return [$path => $value::class];
    }

    $found = [];

    if (is_array($value)) {
        foreach ($value as $key => $item) {
            $found += libraryModelsWithin($item, "{$path}[{$key}]", $seen);
        }

        return $found;
    }

    if (! is_object($value) || isset($seen[spl_object_id($value)])) {
        return $found;
    }

    $seen[spl_object_id($value)] = true;

    foreach (get_object_vars($value) as $name => $item) {
        $found += libraryModelsWithin($item, "{$path}->{$name}", $seen);
    }

    if ($value instanceof Traversable) {
        foreach ($value as $key => $item) {
            $found += libraryModelsWithin($item, "{$path}[{$key}]", $seen);
        }
    }

    return $found;
}

/**
 * The data of every view rendered while this request runs, as [view name, data] pairs.
 *
 * A composer on `*` sees each view as it renders. `View::composer()` cannot be
 * removed, so the listener checks a flag and goes quiet once the request is over.
 *
 * @return list<array{0: string, 1: array<string, mixed>}>
 */
function libraryViewDataDuring(Closure $request): array
{
    $views = [];
    $recording = true;

    View::composer('*', function (Illuminate\Contracts\View\View $view) use (&$views, &$recording): void {
        if ($recording) {
            $views[] = [$view->name(), $view->getData()];
        }
    });

    $request();
    $recording = false;

    return $views;
}

it('finds the models the walk exists to find, and leaves the projection alone', function () {
    $model = Article::factory()->make();

    expect(libraryModelsWithin($model))->toBe(['data' => Article::class])
        ->and(libraryModelsWithin(['a' => ['b' => $model]]))->toBe(['data[a][b]' => Article::class])
        ->and(libraryModelsWithin(['card' => (object) ['article' => $model]]))->toBe(['data[card]->article' => Article::class])
        ->and(libraryModelsWithin(['rows' => new Collection([$model])]))->toHaveKey('data[rows][0]')
        ->and(libraryModelsWithin(['attributes' => new ComponentAttributeBag(['article' => $model])]))->toHaveKey('data[attributes][article]');

    $cycle = new stdClass;
    $cycle->self = $cycle;

    expect(libraryModelsWithin(['article' => new PublicArticle('s', 't', 'd', 'topic', 'a', '2026-01-01', 0), 'x' => 'y', 'n' => null, 'c' => $cycle]))->toBe([]);
});

it('hands no Eloquent model to any view on any publications page, only projections', function () {
    $article = libraryArticle(['topic' => 'Arbitration', 'issued_on' => '2026-02-12']);
    libraryArticle(['topic' => 'Arbitration', 'issued_on' => '2026-02-10']);
    Article::factory()->create(['topic' => 'Arbitration']);
    $unpublished = Article::factory()->create();

    foreach ([
        'the index' => '/publications',
        'a filtered index' => '/publications?q=a&topic=Arbitration&sort=downloads',
        'an empty index' => '/publications?q=no-such-text',
        'an article page' => '/publications/'.$article->slug,
        'an unknown article' => '/publications/no-such-article',
        'an unpublished article' => '/publications/'.$unpublished->slug,
        'a download refusal' => '/publications/no-such-article/download',
    ] as $label => $url) {
        $views = libraryViewDataDuring(fn () => $this->get($url));

        // The control: a walk over no views passes by finding nothing.
        expect($views)->not->toBeEmpty("{$label} rendered no view to walk.");

        foreach ($views as [$name, $data]) {
            expect(libraryModelsWithin($data, $name))->toBe([], "{$label}: a view is handed an Eloquent model.");
        }
    }

    // And what the pages ARE handed is the projection, found by name.
    $index = collect(libraryViewDataDuring(fn () => $this->get('/publications')))->firstWhere(0, 'public.publications.index');
    $show = collect(libraryViewDataDuring(fn () => $this->get('/publications/'.$article->slug)))->firstWhere(0, 'public.publications.show');

    expect($index)->not->toBeNull()
        ->and($show)->not->toBeNull()
        ->and($index[1]['articles'])->toHaveCount(2)
        ->and(array_map(fn (mixed $item): string => $item::class, $index[1]['articles']))->each->toBe(PublicArticle::class)
        ->and($show[1]['article'])->toBeInstanceOf(PublicArticle::class)
        ->and($show[1]['related'])->toHaveCount(1)
        ->and($show[1]['related'][0])->toBeInstanceOf(PublicArticle::class);
});

/*
|--------------------------------------------------------------------------
| Queries, headings, links and cookies
|--------------------------------------------------------------------------
*/

/**
 * Every SQL statement the application ran while serving one request.
 *
 * `DB::listen` cannot be unregistered, so the listener checks a flag and goes
 * quiet once the request is over.
 *
 * @return list<string>
 */
function librarySqlDuring(Closure $request): array
{
    $statements = [];
    $recording = true;

    DB::listen(function (QueryExecuted $query) use (&$statements, &$recording): void {
        if ($recording) {
            $statements[] = $query->sql;
        }
    });

    $request();
    $recording = false;

    return $statements;
}

it('runs the same few queries on the index whether it lists two articles or eight', function () {
    libraryArticle(['topic' => 'Topic 1']);
    libraryArticle(['topic' => 'Topic 2']);

    $few = librarySqlDuring(fn () => $this->get('/publications')->assertOk());

    foreach (range(3, 8) as $number) {
        libraryArticle(['topic' => 'Topic '.$number]);
    }

    $many = librarySqlDuring(fn () => $this->get('/publications')->assertOk());

    // A count and a page for the cards, and one GROUP BY for the chips.
    expect(count($few))->toBeGreaterThanOrEqual(2)->toBeLessThanOrEqual(8)
        ->and(count($many))->toBe(count($few))
        ->and(count($few))->toBe(3);

    foreach ($many as $sql) {
        expect(str_contains($sql, '`articles`'))->toBeTrue("The index read something other than articles: {$sql}");
    }
});

it('runs the same queries on an article page whatever it relates to', function () {
    $alone = libraryArticle(['topic' => 'Alone']);
    $with = libraryArticle(['topic' => 'Crowded']);
    $few = librarySqlDuring(fn () => $this->get('/publications/'.$alone->slug)->assertOk());

    foreach (range(1, 6) as $number) {
        libraryArticle(['topic' => 'Crowded', 'issued_on' => sprintf('2026-01-%02d', $number)]);
    }

    $many = librarySqlDuring(fn () => $this->get('/publications/'.$with->slug)->assertOk());

    // The lookup, and the three-article related list.
    expect(count($few))->toBe(2)
        ->and(count($many))->toBe(2);
});

it('keeps the headings in order, with one h1', function () {
    libraryArticle(['topic' => 'Arbitration']);
    libraryArticle(['topic' => 'Arbitration']);

    foreach (['/publications', '/publications?q=no-such-text', '/publications/'.Article::query()->value('slug')] as $path) {
        $levels = array_column(libraryHeadings(libraryBody($this->get($path)->assertOk())), 0);

        expect($levels[0])->toBe(1, $path)
            ->and(count(array_keys($levels, 1, true)))->toBe(1, $path);

        // Never a jump of more than one level down.
        foreach ($levels as $index => $level) {
            if ($index > 0) {
                expect($level)->toBeLessThanOrEqual($levels[$index - 1] + 1, $path);
            }
        }
    }
});

it('puts Publications in the navigation between Programmes and the verifier, and in the footer', function () {
    $html = libraryBody($this->get('/publications')->assertOk());

    $nav = substr($html, (int) strpos($html, '<nav aria-label='), (int) strpos($html, '</nav>') - (int) strpos($html, '<nav aria-label='));
    $footer = substr($html, (int) strpos($html, '<footer'), (int) strpos($html, '</footer>') - (int) strpos($html, '<footer'));

    // The navigation is rendered twice from one list, narrow then wide. The narrow
    // menu's last item is the student portal's sign-in (P4-T15), which the wide row
    // does not repeat.
    $expected = [url('/about'), url('/programmes'), url('/publications'), url('/verify/certificates'), url('/contact')];
    $portal = route('filament.student.auth.login');

    expect(array_values(array_unique(array_column(libraryAnchors($nav), 'href'))))->toBe([...$expected, $portal])
        ->and(array_column(libraryAnchors($nav), 'href'))->toHaveCount(count($expected) * 2 + 1)
        ->and(array_column(libraryAnchors($footer), 'href'))->toContain(url('/publications'));
});

it('names the three routes and puts them under the public group', function () {
    expect(route('public.publications.index'))->toBe(url('/publications'))
        ->and(route('public.publications.show', ['slug' => 'a-b']))->toBe(url('/publications/a-b'))
        ->and(route('public.publications.download', ['slug' => 'a-b']))->toBe(url('/publications/a-b/download'));
});

it('sets no cookie on the index, a filtered index or an article page', function () {
    $article = libraryArticle();

    foreach (['/publications', '/publications?q=x&topic=y&sort=downloads', '/publications/'.$article->slug] as $path) {
        $response = $this->get($path)->assertOk();

        expect($response->headers->getCookies())->toBe([], "{$path} set a cookie.")
            ->and($response->headers->has('Set-Cookie'))->toBeFalse("{$path} sent a Set-Cookie header.");
    }
});

it('runs no script on any publications page', function () {
    $article = libraryArticle();

    foreach (['/publications', '/publications/'.$article->slug] as $path) {
        expect(str_contains(strtolower(libraryBody($this->get($path)->assertOk())), '<script'))->toBeFalse($path);
    }
});
