<?php

declare(strict_types=1);

use App\Domain\Enrollment\Models\Batch;
use App\Domain\Enrollment\Models\Course;
use App\Domain\Finance\Services\PricingService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

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

it('has no card for an inactive course on either page, and a 404 identical to an unknown code', function () {
    courseWithBatch('2026-10-20', ['name_en' => 'Active Course']);
    $retired = courseWithBatch('2026-10-21', ['name_en' => 'Retired Course', 'code' => 'RETIRED-1', 'is_active' => false]);

    foreach (['/', '/programmes'] as $path) {
        $cards = programmeCardTexts((string) $this->get($path)->assertOk()->getContent());

        expect(array_keys($cards))->toBe(['Active Course']);
    }

    $inactive = $this->get('/programmes/'.$retired->code)->assertNotFound();
    $unknown = $this->get('/programmes/NO-SUCH-CODE')->assertNotFound();

    // Same body and same headers that matter, so the response does not say which
    // of "retired" and "never existed" this was. Not the rate-limit counters,
    // which fall by one per request.
    expect($inactive->getContent())->toBe($unknown->getContent())
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

it('links each card to its programme page by course code', function () {
    courseWithBatch('2026-10-20', ['name_en' => 'Linked Course', 'code' => 'LINK-001']);

    $html = (string) $this->get('/programmes')->assertOk()->getContent();

    expect($html)->toContain('href="'.url('/programmes/LINK-001').'"');
});

it('never shows seats, capacity or an enrolment count', function () {
    courseWithBatch('2026-10-20', ['name_en' => 'Busy Course'], ['capacity' => 17]);

    foreach (['/', '/programmes', '/programmes/'.Course::query()->firstOrFail()->code] as $path) {
        $text = strtolower((string) $this->get($path)->assertOk()->getContent());

        foreach (['seat', 'capacity', 'enrol'] as $word) {
            expect(str_contains($text, $word))->toBeFalse("{$path} mentions [{$word}].");
        }
    }
});

it('touches neither enrollments nor capacity in any query, on any page', function () {
    $course = courseWithBatch('2026-10-20', ['name_en' => 'Queried Course'], ['capacity' => 17]);
    Course::factory()->create(['name_en' => 'Fallback Queried Course']);

    foreach (['/', '/programmes', '/programmes/'.$course->code] as $path) {
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

        $code = Course::query()->orderBy('id')->firstOrFail()->code;

        foreach (['/', '/programmes', "/programmes/{$code}"] as $path) {
            $counts[$path][$total] = count(sqlStatementsDuring(fn () => $this->get($path)->assertOk()));
        }
    }

    foreach ($counts as $path => $byTotal) {
        expect($byTotal[2])->toBe($byTotal[6], "{$path} runs more queries as courses are added.")
            ->and($byTotal[6])->toBeLessThanOrEqual(4);
    }
});

it('shows the programme page with its details, or the contact call to action when there is no batch', function () {
    courseWithBatch('2026-10-20',
        ['name_en' => 'Detail Course', 'code' => 'DETAIL-1', 'description_en' => 'The detail description.', 'total_hours' => 30, 'default_price' => '640.125'],
        ['price' => null, 'total_hours' => null],
    );
    Course::factory()->create(['name_en' => 'Bare Course', 'code' => 'BARE-1', 'description_en' => 'The bare description.', 'total_hours' => 18, 'default_price' => '321.000']);

    $detail = (string) $this->get('/programmes/DETAIL-1')->assertOk()->getContent();
    $bare = (string) $this->get('/programmes/BARE-1')->assertOk()->getContent();

    expect(preg_match_all('/<h1\b[^>]*>\s*Detail Course\s*<\/h1>/s', $detail))->toBe(1)
        ->and($detail)->toContain('The detail description.')->toContain('30 hours')->toContain('640.125 LYD')->toContain('Starts 20 Oct 2026')
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
