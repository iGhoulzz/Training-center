<?php

declare(strict_types=1);

use App\Domain\Enrollment\Data\PublicProgramme;
use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Course;
use App\Domain\Enrollment\Queries\PublicProgrammeCatalogue;
use App\Domain\Finance\Services\PricingService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// Livewire state left by an earlier file's panel render would inject its script
// into these pages; PublicPagesTest explains. Flushed before each test.
beforeEach(function (): void {
    Livewire::flushState();
});

/*
|--------------------------------------------------------------------------
| The programme cards (P4-T12)
|--------------------------------------------------------------------------
|
| A card shows what the centre has actually committed to: the next planned
| intake's start date, price and hours. It never shows seats, and a course with
| no planned intake shows neither a date nor a price, because a price with no
| batch behind it is a promise the centre has not made.
|
| Every expected value below is written out by hand from the fixture. The
| PricingService and `effective_total_hours` cross-checks sit beside them, never
| in place of them: a card that agreed with the service while both were wrong
| would otherwise pass.
|
| Today is fixed at 2026-10-12, so every start date is a literal and so is the
| text built from it.
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 12:00:00', 'UTC'));
});

/**
 * The visible text of every programme card on a page, keyed by the course name.
 *
 * A card is an <article>, and its first link is the course name. The keys keep
 * the page's order, which the ordering tests read.
 *
 * @return array<string, string>
 */
function programmeCardTexts(string $html): array
{
    preg_match_all('/<article\b.*?<\/article>/s', $html, $articles);

    $cards = [];

    foreach ($articles[0] as $article) {
        preg_match('/<a\b[^>]*>(.*?)<\/a>/s', $article, $link);

        $name = trim(html_entity_decode(strip_tags($link[1] ?? '')));
        $cards[$name] = trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($article))));
    }

    return $cards;
}

/**
 * The <article> of the card whose name link reads $name, or '' when there is none.
 */
function programmeCardHtml(string $html, string $name): string
{
    preg_match_all('/<article\b.*?<\/article>/s', $html, $articles);

    foreach ($articles[0] as $article) {
        if (preg_match('/<a\b[^>]*>\s*'.preg_quote($name, '/').'\s*<\/a>/', $article) === 1) {
            return $article;
        }
    }

    return '';
}

/**
 * The inside of every programme-facts list in this HTML, whitespace collapsed and
 * joined with a space, in page order.
 *
 * A card holds the facts in two lists, the hours at its top and the price and start
 * date at its foot, because the design puts its name and description between them; the
 * programme page holds one list. Read together, in order, they must say the same thing.
 * The lists' own tags are left out because the caller's classes differ between a card
 * and the programme page; what must not differ is what they say.
 */
function programmeFactsFragment(string $html): string
{
    preg_match_all('/<ul\b[^>]*\bdata-programme-facts\b[^>]*>(.*?)<\/ul>/s', $html, $matches);

    return trim((string) preg_replace('/\s+/', ' ', implode(' ', $matches[1])));
}

/**
 * The visible text of a page, whitespace collapsed.
 */
function programmePageText(string $html): string
{
    return trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html))));
}

/**
 * Every SQL statement the application ran while serving one GET.
 *
 * `DB::listen` cannot be unregistered, so the listener checks a flag and goes
 * quiet once the request is over.
 *
 * @return array<int, string>
 */
function sqlStatementsDuring(Closure $request): array
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

/**
 * Create an active course with a planned batch starting on $start.
 *
 * @param  array<string, mixed>  $course
 * @param  array<string, mixed>  $batch
 */
function courseWithBatch(string $start, array $course = [], array $batch = []): Course
{
    $created = Course::factory()->create($course);
    Batch::factory()->for($created)->create(['start_date' => $start] + $batch);

    return $created;
}

it('shows each card with the price, hours and start date of its next batch', function () {
    // Own price and own hours, over a course that says something different.
    courseWithBatch('2026-10-20',
        ['name_en' => 'Own Terms Course', 'total_hours' => 60, 'default_price' => '100.000'],
        ['price' => '1450.000', 'total_hours' => 48],
    );
    // Inherits BOTH: a null batch price and null batch hours read the course live.
    courseWithBatch('2026-11-03',
        ['name_en' => 'Inheriting Course', 'total_hours' => 36, 'default_price' => '900.500'],
        ['price' => null, 'total_hours' => null],
    );
    // Own price, inherited hours.
    courseWithBatch('2026-11-17',
        ['name_en' => 'Own Price Course', 'total_hours' => 24, 'default_price' => '10.000'],
        ['price' => '75.250', 'total_hours' => null],
    );
    // Inherited price, own hours.
    courseWithBatch('2026-12-01',
        ['name_en' => 'Own Hours Course', 'total_hours' => 90, 'default_price' => '640.125'],
        ['price' => null, 'total_hours' => 12],
    );

    foreach (['/', '/programmes'] as $path) {
        $cards = programmeCardTexts((string) $this->get($path)->assertOk()->getContent());

        expect($cards['Own Terms Course'])->toContain('48 hours')->toContain('1450.000 LYD')->toContain('Starts 20 Oct 2026')
            ->not->toContain('100.000')->not->toContain('60 hours')
            ->and($cards['Inheriting Course'])->toContain('36 hours')->toContain('900.500 LYD')->toContain('Starts 03 Nov 2026')
            ->and($cards['Own Price Course'])->toContain('24 hours')->toContain('75.250 LYD')->not->toContain('10.000')
            ->and($cards['Own Hours Course'])->toContain('12 hours')->toContain('640.125 LYD')->not->toContain('90 hours');
    }
});

it('agrees with PricingService and effective_total_hours for the chosen batch', function () {
    // The cross-check, never the only expectation: the literals above are the
    // expectation, and this asserts the card also tells the service's story.
    $own = courseWithBatch('2026-10-20',
        ['name_en' => 'Own Terms Course', 'total_hours' => 60, 'default_price' => '100.000'],
        ['price' => '1450.000', 'total_hours' => 48],
    );
    $inheriting = courseWithBatch('2026-11-03',
        ['name_en' => 'Inheriting Course', 'total_hours' => 36, 'default_price' => '900.500'],
        ['price' => null, 'total_hours' => null],
    );

    $cards = programmeCardTexts((string) $this->get('/programmes')->assertOk()->getContent());

    foreach ([[$own, 'Own Terms Course', '1450.000', 48], [$inheriting, 'Inheriting Course', '900.500', 36]] as [$course, $name, $price, $hours]) {
        $batch = Batch::query()->where('course_id', $course->id)->firstOrFail();

        expect(app(PricingService::class)->priceForBatch($batch)->toDecimal())->toBe($price)
            ->and($batch->effective_total_hours)->toBe($hours)
            ->and($cards[$name])->toContain("{$price} LYD")->toContain("{$hours} hours");
    }
});

it('chooses the earliest planned batch that has not started', function () {
    $course = Course::factory()->create(['name_en' => 'Chosen Course', 'total_hours' => 40, 'default_price' => '1.000']);

    // The one that must win: planned, in the future, the earliest of those.
    Batch::factory()->for($course)->create(['start_date' => '2026-10-20', 'price' => '111.000', 'total_hours' => 11]);
    // Planned and later.
    Batch::factory()->for($course)->create(['start_date' => '2026-11-10', 'price' => '222.000', 'total_hours' => 22]);
    // Planned but already past.
    Batch::factory()->for($course)->create(['start_date' => '2026-09-01', 'price' => '333.000', 'total_hours' => 33]);
    // Earlier than the winner and in the future, but not planned.
    Batch::factory()->for($course)->active()->create(['start_date' => '2026-10-14', 'price' => '444.000', 'total_hours' => 44]);
    Batch::factory()->for($course)->cancelled()->create(['start_date' => '2026-10-15', 'price' => '555.000', 'total_hours' => 55]);
    Batch::factory()->for($course)->completed()->create(['start_date' => '2026-10-16', 'price' => '666.000', 'total_hours' => 66]);

    $card = programmeCardTexts((string) $this->get('/programmes')->assertOk()->getContent())['Chosen Course'];

    expect($card)->toContain('111.000 LYD')->toContain('11 hours')->toContain('Starts 20 Oct 2026');

    foreach (['222', '333', '444', '555', '666'] as $other) {
        expect(str_contains($card, "{$other}.000"))->toBeFalse("The card shows the {$other}.000 batch, which is not the next planned one.");
    }
});

it('renders the fallback with the name, description, hours and a contact link, and no price or date', function () {
    // The only course, so any "LYD" or "Starts" anywhere on the page is the
    // fallback card leaking one. Its default price is a sentinel nobody could
    // mistake for anything else.
    $course = Course::factory()->create([
        'name_en' => 'Fallback Course',
        'description_en' => 'A description written for the fallback card.',
        'total_hours' => 24,
        'default_price' => '777.777',
    ]);
    // Batches that must not count: one past, one cancelled.
    Batch::factory()->for($course)->create(['start_date' => '2026-09-01', 'price' => '888.888']);
    Batch::factory()->for($course)->cancelled()->create(['start_date' => '2026-10-20', 'price' => '999.999']);

    foreach (['/', '/programmes'] as $path) {
        $html = (string) $this->get($path)->assertOk()->getContent();
        $card = programmeCardTexts($html)['Fallback Course'];

        expect($card)->toContain('A description written for the fallback card.')->toContain('24 hours')
            ->and($html)->toContain('href="'.url('/contact').'"')
            ->and(str_contains($html, 'LYD'))->toBeFalse('A price is on the page for a course with no upcoming batch.')
            ->and(str_contains($html, 'Starts'))->toBeFalse('A start date is on the page for a course with no upcoming batch.')
            ->and(str_contains($html, '777.777'))->toBeFalse('The course default price is on the page.');
    }
});

it('has no card for an inactive course on either page, and a 404 identical to an unknown id', function () {
    courseWithBatch('2026-10-20', ['name_en' => 'Active Course']);
    $retired = courseWithBatch('2026-10-21', ['name_en' => 'Retired Course', 'code' => 'RETIRED-1', 'is_active' => false]);

    foreach (['/', '/programmes'] as $path) {
        $cards = programmeCardTexts((string) $this->get($path)->assertOk()->getContent());

        expect(array_keys($cards))->toBe(['Active Course']);
    }

    $inactive = $this->get('/programmes/'.$retired->id)->assertNotFound();
    $unknown = $this->get('/programmes/'.($retired->id + 1000))->assertNotFound();
    // The retired course's CODE is not an address either: the route takes an id.
    $byCode = $this->get('/programmes/RETIRED-1')->assertNotFound();
    // An id too long for an integer is an unknown id, not a server error.
    $tooLarge = $this->get('/programmes/99999999999999999999999')->assertNotFound();

    // Same body and same headers that matter, so the response does not say which
    // of "retired" and "never existed" this was. Not the rate-limit counters,
    // which fall by one per request.
    expect($inactive->getContent())->toBe($unknown->getContent())
        ->and($byCode->getContent())->toBe($unknown->getContent())
        ->and($tooLarge->getContent())->toBe($unknown->getContent())
        ->and($inactive->headers->get('Content-Type'))->toBe($unknown->headers->get('Content-Type'))
        ->and($inactive->headers->get('Cache-Control'))->toBe($unknown->headers->get('Cache-Control'))
        ->and($inactive->headers->getCookies())->toBe([])
        ->and($unknown->headers->getCookies())->toBe([]);
});

it('orders courses with an upcoming batch first, by start date then name, and the rest by name', function () {
    Course::factory()->create(['name_en' => 'Zeta Fallback']);
    courseWithBatch('2026-11-03', ['name_en' => 'Late Course']);
    Course::factory()->create(['name_en' => 'Alpha Fallback']);
    courseWithBatch('2026-10-20', ['name_en' => 'Contract Drafting']);
    courseWithBatch('2026-10-20', ['name_en' => 'Aardvark Law']);

    $names = array_keys(programmeCardTexts((string) $this->get('/programmes')->assertOk()->getContent()));

    expect($names)->toBe(['Aardvark Law', 'Contract Drafting', 'Late Course', 'Alpha Fallback', 'Zeta Fallback']);
});

it('shows the first four on the home page with a link to all of them, and every course on the programmes page', function () {
    foreach ([1, 2, 3, 4, 5, 6] as $n) {
        courseWithBatch('2026-10-'.(11 + $n * 2), ['name_en' => "Course Number {$n}"]);
    }

    $home = (string) $this->get('/')->assertOk()->getContent();
    $index = (string) $this->get('/programmes')->assertOk()->getContent();

    expect(array_keys(programmeCardTexts($home)))->toBe(['Course Number 1', 'Course Number 2', 'Course Number 3', 'Course Number 4'])
        ->and(array_keys(programmeCardTexts($index)))->toHaveCount(6)
        ->and($home)->toContain('href="'.url('/programmes').'"');
});

it('keeps the headings in order: h3 cards under the home h2, h2 cards under the programmes h1', function () {
    courseWithBatch('2026-10-20', ['name_en' => 'Heading Course']);

    $home = (string) $this->get('/')->assertOk()->getContent();
    $index = (string) $this->get('/programmes')->assertOk()->getContent();

    expect(preg_match('/<h3\b[^>]*>\s*<a\b[^>]*>\s*Heading Course\s*<\/a>/s', $home))->toBe(1)
        ->and(preg_match('/<h2\b[^>]*>\s*<a\b[^>]*>\s*Heading Course\s*<\/a>/s', $index))->toBe(1)
        ->and(preg_match_all('/<h1\b/', $index))->toBe(1);
});

it('links each card to its programme page by course id', function () {
    $course = courseWithBatch('2026-10-20', ['name_en' => 'Linked Course', 'code' => 'LINK-001']);

    $html = (string) $this->get('/programmes')->assertOk()->getContent();

    expect($html)->toContain('href="'.url('/programmes/'.$course->id).'"')
        ->and(str_contains($html, 'LINK-001'))->toBeFalse('The course code is in the page, and the URL should not need it.');
});

it('gives a working card link to a course whose code holds a slash, a question mark or a hash', function (string $code) {
    // Codes are typed by hand with no character rule, and the URL generator leaves
    // these three unencoded, so a link built from the code would 404.
    $course = courseWithBatch('2026-10-20', ['name_en' => 'Odd Code Course', 'code' => $code]);

    $html = (string) $this->get('/programmes')->assertOk()->getContent();
    preg_match('/<a\b[^>]*\bhref="([^"]*)"[^>]*>\s*Odd Code Course\s*<\/a>/', $html, $link);

    expect($link[1] ?? null)->toBe(url('/programmes/'.$course->id));

    $page = (string) $this->get($link[1])->assertOk()->getContent();

    expect(preg_match_all('/<h1\b[^>]*>\s*Odd Code Course\s*<\/h1>/s', $page))->toBe(1);
})->with([
    'slash' => ['ARB/1'],
    'question mark' => ['CIV?2'],
    'hash' => ['LAW#3'],
]);

it('shows the same facts on a card and on its programme page', function () {
    $planned = courseWithBatch('2026-10-20',
        ['name_en' => 'Facts Course', 'total_hours' => 30, 'default_price' => '640.125'],
        ['price' => null, 'total_hours' => null],
    );
    $bare = Course::factory()->create(['name_en' => 'Bare Facts Course', 'total_hours' => 18, 'default_price' => '321.000']);

    $index = (string) $this->get('/programmes')->assertOk()->getContent();

    // One fragment, rendered by one component, so the two pages cannot word a
    // date or a price differently. Compared as rendered HTML, then pinned by hand.
    foreach ([[$planned, 'Facts Course', '30 hours 640.125 LYD Starts 20 Oct 2026'], [$bare, 'Bare Facts Course', null]] as [$course, $name, $text]) {
        $onCard = programmeFactsFragment(programmeCardHtml($index, $name));
        $onPage = programmeFactsFragment((string) $this->get('/programmes/'.$course->id)->assertOk()->getContent());

        expect($onCard)->not->toBe('', "No facts list on the card for {$name}.")
            ->and($onPage)->toBe($onCard);

        $plain = trim((string) preg_replace('/\s+/', ' ', strip_tags($onCard)));

        if ($text !== null) {
            expect($plain)->toBe($text);
        } else {
            expect($plain)->toStartWith('18 hours ')->and(str_contains($plain, 'LYD'))->toBeFalse()->and(str_contains($plain, 'Starts'))->toBeFalse();
        }
    }
});

it('never shows seats, capacity or an enrolment count', function () {
    courseWithBatch('2026-10-20', ['name_en' => 'Busy Course'], ['capacity' => 17]);

    // The sentences on these pages that may say "enrol" or "seat", each cut out by its
    // exact rendered text and counted. The footer's portal note is the design's own copy
    // (a login is issued "when you enrol"); on the home page the design's "Open for
    // enrolment" heading and "Request a seat" button are the plan's decided copy. None
    // names a seat, a place or a count. The rest of the page, header and footer
    // included, is held to the old rule.
    $note = e(__('public.portal.note'));
    $allowed = [
        '/' => [$note => 1, e(__('public.home.programmes_title')) => 1, e(__('public.home.request_seat')) => 1],
        '/programmes' => [$note => 1],
        '/programmes/'.Course::query()->firstOrFail()->id => [$note => 1],
    ];

    foreach ($allowed as $path => $sentences) {
        $html = (string) $this->get($path)->assertOk()->getContent();

        // The control: a cut that found nothing would pass for having cut the sentence.
        foreach ($sentences as $sentence => $times) {
            expect(substr_count($html, $sentence))->toBe($times, "{$path} does not render [{$sentence}] {$times} time(s).");
        }

        $text = strtolower(str_replace(array_keys($sentences), '', $html));

        foreach (['seat', 'capacity', 'enrol'] as $word) {
            expect(str_contains($text, $word))->toBeFalse("{$path} mentions [{$word}].");
        }
    }
});

it('touches neither enrollments nor capacity in any query, on any page', function () {
    $course = courseWithBatch('2026-10-20', ['name_en' => 'Queried Course'], ['capacity' => 17]);
    Course::factory()->create(['name_en' => 'Fallback Queried Course']);

    foreach (['/', '/programmes', '/programmes/'.$course->id] as $path) {
        $statements = sqlStatementsDuring(fn () => $this->get($path)->assertOk());

        expect($statements)->not->toBeEmpty("{$path} ran no query, so this assertion would pass over nothing.");

        foreach ($statements as $sql) {
            foreach (['enrollments', 'capacity'] as $word) {
                expect(str_contains(strtolower($sql), $word))->toBeFalse("{$path} ran a statement that mentions [{$word}]: {$sql}");
            }
        }
    }
});

it('runs the same number of queries for two courses as for six', function () {
    $counts = [];

    foreach ([2, 6] as $total) {
        while (Course::query()->count() < $total) {
            $n = Course::query()->count();

            // Alternate batch and fallback, so both card shapes are in the count.
            if ($n % 2 === 0) {
                courseWithBatch('2026-10-'.(20 + $n), ['name_en' => "Counted Course {$n}"]);
            } else {
                Course::factory()->create(['name_en' => "Counted Course {$n}"]);
            }
        }

        $id = Course::query()->orderBy('id')->firstOrFail()->id;

        foreach (['/', '/programmes', "/programmes/{$id}"] as $path) {
            $counts[$path][$total] = count(sqlStatementsDuring(fn () => $this->get($path)->assertOk()));
        }
    }

    // Two: NextUpcomingBatch's one, and the active courses (or the one course) as the
    // other. The home page adds a third, its publications teaser (P4-T20b).
    $expected = ['/' => 3, '/programmes' => 2, "/programmes/{$id}" => 2];

    foreach ($counts as $path => $byTotal) {
        expect($byTotal[2])->toBe($byTotal[6], "{$path} runs more queries as courses are added.")
            ->and($byTotal[6])->toBe($expected[$path]);
    }
});

it('shows the programme page with its details, or the contact call to action when there is no batch', function () {
    $detailCourse = courseWithBatch('2026-10-20',
        ['name_en' => 'Detail Course', 'description_en' => 'The detail description.', 'total_hours' => 30, 'default_price' => '640.125'],
        ['price' => null, 'total_hours' => null],
    );
    $bareCourse = Course::factory()->create(['name_en' => 'Bare Course', 'description_en' => 'The bare description.', 'total_hours' => 18, 'default_price' => '321.000']);

    $detail = (string) $this->get('/programmes/'.$detailCourse->id)->assertOk()->getContent();
    $bare = (string) $this->get('/programmes/'.$bareCourse->id)->assertOk()->getContent();

    // The price and its currency are two elements (the currency is its own smaller
    // span, set off by a space), so the price is read from the page's text.
    expect(preg_match_all('/<h1\b[^>]*>\s*Detail Course\s*<\/h1>/s', $detail))->toBe(1)
        ->and($detail)->toContain('The detail description.')->toContain('30 hours')->toContain('Starts 20 Oct 2026')
        ->and(programmePageText($detail))->toContain('640.125 LYD')
        ->and(preg_match_all('/<h1\b[^>]*>\s*Bare Course\s*<\/h1>/s', $bare))->toBe(1)
        ->and($bare)->toContain('The bare description.')->toContain('18 hours')->toContain('href="'.url('/contact').'"')
        ->and(str_contains($bare, 'LYD'))->toBeFalse()
        ->and(str_contains($bare, 'Starts'))->toBeFalse()
        ->and(str_contains($bare, '321.000'))->toBeFalse();
});

it('says so on both pages when there are no programmes to show', function () {
    Course::factory()->inactive()->create();

    // A sentinel rather than the English copy, which the owner will replace. The
    // group is loaded first: addLines() into an unloaded group would leave the
    // file unread and blank every other key in it.
    app('translator')->load('*', 'public', 'en');
    app('translator')->addLines(['public.programmes.empty' => 'SENTINEL-NO-PROGRAMMES'], 'en');

    foreach (['/', '/programmes'] as $path) {
        $html = (string) $this->get($path)->assertOk()->getContent();

        expect($html)->toContain('SENTINEL-NO-PROGRAMMES')
            ->and(programmeCardTexts($html))->toBe([]);
    }
});

it('resolves a course description in the reader locale, with the same rule as the name', function () {
    $course = Course::factory()->make([
        'name_en' => 'English name',
        'name_ar' => 'الاسم العربي',
        'description_en' => 'English description',
        'description_ar' => 'الوصف العربي',
    ]);
    $untranslated = Course::factory()->make(['description_en' => 'English only', 'description_ar' => null]);
    $empty = Course::factory()->make(['description_en' => null, 'description_ar' => null]);

    app()->setLocale('en');
    expect($course->description())->toBe('English description')
        ->and($untranslated->description())->toBe('English only')
        ->and($empty->description())->toBe('');

    app()->setLocale('ar');
    expect($course->description())->toBe('الوصف العربي')
        ->and($untranslated->description())->toBe('English only')
        // The twin must follow name() exactly: the two cannot disagree about when Arabic applies.
        ->and($course->name())->toBe('الاسم العربي');
});

it('pins the public programme projection to its exact property list', function () {
    // Adding a property is how a new column would reach the public pages, so it
    // must be a deliberate change HERE as well as in PublicProgramme and in the
    // catalogue line that fills it.
    $class = new ReflectionClass(PublicProgramme::class);

    $types = [];

    foreach ($class->getProperties() as $property) {
        expect($property->isPublic())->toBeTrue("{$property->getName()} is not public.")
            ->and($property->isReadOnly())->toBeTrue("{$property->getName()} is not readonly.");

        $types[$property->getName()] = (string) $property->getType();
    }

    expect($class->isFinal())->toBeTrue()
        ->and($class->isReadOnly())->toBeTrue()
        ->and($types)->toBe([
            'id' => 'int',
            'name' => 'string',
            'description' => 'string',
            'hours' => 'int',
            'price' => '?string',
            'startDate' => '?string',
        ]);
});

it('builds each programme by naming its fields, from hand-computed values', function () {
    $planned = courseWithBatch('2026-10-20',
        ['name_en' => 'Projected Course', 'description_en' => 'Projected description.', 'total_hours' => 60, 'default_price' => '100.000'],
        ['price' => '1450.000', 'total_hours' => 48, 'capacity' => 17],
    );
    $inheriting = courseWithBatch('2026-11-03',
        ['name_en' => 'Inheriting Projection', 'description_en' => null, 'total_hours' => 36, 'default_price' => '900.500'],
        ['price' => null, 'total_hours' => null],
    );
    $bare = Course::factory()->create(['name_en' => 'Bare Projection', 'description_en' => 'Bare description.', 'total_hours' => 18, 'default_price' => '321.000']);
    $retired = courseWithBatch('2026-10-21', ['name_en' => 'Retired Projection', 'is_active' => false]);

    $catalogue = app(PublicProgrammeCatalogue::class);

    $expected = [
        new PublicProgramme(id: $planned->id, name: 'Projected Course', description: 'Projected description.', hours: 48, price: '1450.000', startDate: '2026-10-20'),
        new PublicProgramme(id: $inheriting->id, name: 'Inheriting Projection', description: '', hours: 36, price: '900.500', startDate: '2026-11-03'),
        new PublicProgramme(id: $bare->id, name: 'Bare Projection', description: 'Bare description.', hours: 18, price: null, startDate: null),
    ];

    expect($catalogue->cards())->toEqual($expected)
        ->and($catalogue->find($planned->id))->toEqual($expected[0])
        ->and($catalogue->find($inheriting->id))->toEqual($expected[1])
        ->and($catalogue->find($bare->id))->toEqual($expected[2])
        // Inactive and unknown are the same answer, and neither loads a row into a projection.
        ->and($catalogue->find($retired->id))->toBeNull()
        ->and($catalogue->find($retired->id + 1000))->toBeNull();
});
