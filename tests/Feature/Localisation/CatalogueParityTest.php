<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Translation\MessageSelector;

/*
 * Declared although this file writes no row: DatabaseIsolationTest is fail-closed
 * and asks every feature test to name its isolation. The plural proof below boots
 * the application for its translator, and nothing else here touches a table.
 */
uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The Arabic catalogues have the English catalogues' shape (P4-T17a)
|--------------------------------------------------------------------------
|
| WHY THE FILES ARE READ, NOT THE TRANSLATOR. Laravel's translator falls back to
| English for a key the Arabic file lacks, so asking it for every key would return
| a string either way and a missing translation would look like a present one.
| That is the silence lang/ar/*.php kept visible by staying empty until now. The
| two files are therefore compared directly.
|
| WHAT IS CHECKED, FOR EACH CATALOGUE:
|
|   1. The Arabic file has exactly the English file's keys, nested groups
|      included: none missing, none extra.
|   2. Every value carries exactly the English value's :placeholders. A placeholder
|      may move inside the sentence, but it is never translated or dropped.
|   3. A value that is pluralised in English is pluralised in Arabic, in Arabic's
|      OWN six forms. Laravel numbers them zero, one, two, few (3-10), many
|      (11-99), other (100 and over), and it reads the form from `count % 100`, so
|      103 is "few" and 111 is "many". The English file's two segments cannot be
|      copied across: the selector would answer every count past one with the
|      first segment and nothing would fail.
|   4. Every value is filled: not empty, and written in Arabic script.
|
| THE CHECKERS ARE FUNCTIONS WITH SELF-TESTS. Each takes plain arrays and strings,
| and each has a planted sample it must fail. A checker that quietly accepts a
| broken catalogue reads as enforcement while enforcing nothing, which is how this
| project's earlier scanners were found out (LocalizationTest and DesignSystemTest
| do the same).
|
| T17 EXTENDS THIS FILE BY REPLACING catalogueParityNames() with a derivation from
| lang/en, and by adding to CATALOGUE_PARITY_IDENTICAL whatever it must. Nothing
| below names a catalogue.
*/

/**
 * Which Arabic plural form Laravel's selector uses for a count, written out by
 * hand from the CLDR rule for Arabic rather than read from the selector.
 *
 * The keys are the counts under test; the values are the form (0 zero, 1 one,
 * 2 two, 3 few, 4 many, 5 other) the rule gives them. Each range appears at both
 * ends, and 100 to 103 and 111 are there for the hundreds, where the rule
 * restarts: 103 is "few" again and 111 "many" again.
 */
const CATALOGUE_PARITY_ARABIC_FORMS = [
    0 => 0,
    1 => 1,
    2 => 2,
    3 => 3,
    7 => 3,
    10 => 3,
    11 => 4,
    50 => 4,
    99 => 4,
    100 => 5,
    101 => 5,
    102 => 5,
    103 => 3,
    111 => 4,
    200 => 5,
    1000 => 5,
];

/**
 * The only values whose Arabic is allowed to be the English, character for
 * character. Each is here for a reason, and a key whose Arabic differs from its
 * English is refused as an entry: the list cannot drift into hiding a translation.
 *
 * A value with no words in it at all (`© :year :name`, `:page | :name`, `:name`) is
 * not listed. It has nothing to translate, and catalogueParityHasWords() exempts
 * it by what it is.
 *
 * @var array<string, string> catalogue key => why Arabic readers see it unchanged
 */
const CATALOGUE_PARITY_IDENTICAL = [
    'public.home.verify_placeholder' => 'A sample reference in the real format, TC, the year and eight characters. It is a code, written the same in every language.',
    'public.programmes.date_format' => 'A PHP date() pattern, not text. The month name it prints is localised by the date library at render time.',
    'publications.public.date_format' => 'The same date() pattern as public.programmes.date_format.',
    'publications.public.pdf' => 'The name of a file format. Arabic readers see "PDF" in Latin letters, as on the file itself.',
];

/**
 * The catalogues compared. T17 replaces this with every file in lang/en.
 *
 * Read from the file system and not through the application: a Pest dataset is
 * resolved while the tests are being collected, before any application exists.
 *
 * @return list<string>
 */
function catalogueParityNames(): array
{
    return ['public', 'publications', 'verify'];
}

/**
 * One catalogue file's array, as the file returns it.
 *
 * @return array<string, mixed>
 */
function catalogueParityFile(string $locale, string $name): array
{
    $path = dirname(__DIR__, 3).DIRECTORY_SEPARATOR.'lang'.DIRECTORY_SEPARATOR.$locale.DIRECTORY_SEPARATOR.$name.'.php';

    return require $path;
}

/**
 * A catalogue's leaves, keyed by their dot path: `home.heading`, or with a prefix
 * `public.home.heading`, which is the key the application asks the translator for.
 *
 * @param  array<array-key, mixed>  $catalogue
 * @return array<string, mixed>
 */
function catalogueParityFlatten(array $catalogue, string $prefix = ''): array
{
    $flat = [];

    foreach ($catalogue as $key => $value) {
        $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

        if (is_array($value)) {
            foreach (catalogueParityFlatten($value, $path) as $leaf => $leafValue) {
                $flat[$leaf] = $leafValue;
            }

            continue;
        }

        $flat[$path] = $value;
    }

    return $flat;
}

/**
 * Every key of one catalogue with its English and its Arabic value, the Arabic
 * `null` when the Arabic file has no such key. Keys only the Arabic file has are
 * not here; catalogueParityKeyDifferences() reports them.
 *
 * @return array<string, array{english: mixed, arabic: mixed}>
 */
function catalogueParityPairs(string $name): array
{
    $english = catalogueParityFlatten(catalogueParityFile('en', $name), $name);
    $arabic = catalogueParityFlatten(catalogueParityFile('ar', $name), $name);

    $pairs = [];

    foreach ($english as $key => $value) {
        $pairs[$key] = ['english' => $value, 'arabic' => $arabic[$key] ?? null];
    }

    return $pairs;
}

/**
 * The keys one catalogue has and the other lacks.
 *
 * @param  array<array-key, mixed>  $english
 * @param  array<array-key, mixed>  $arabic
 * @return array{missing: list<string>, extra: list<string>}
 */
function catalogueParityKeyDifferences(array $english, array $arabic): array
{
    $englishKeys = array_map('strval', array_keys(catalogueParityFlatten($english)));
    $arabicKeys = array_map('strval', array_keys(catalogueParityFlatten($arabic)));

    return [
        'missing' => array_values(array_diff($englishKeys, $arabicKeys)),
        'extra' => array_values(array_diff($arabicKeys, $englishKeys)),
    ];
}

/**
 * The named placeholders in a value, sorted and without repeats: `:count`,
 * `:topic`. A colon followed by a space, as in "Download PDF: :title" or an Arabic
 * "مثال: TC-2026-…", is punctuation and does not match.
 *
 * @return list<string> the names without their colon
 */
function catalogueParityPlaceholders(string $value): array
{
    preg_match_all('/:([A-Za-z_][A-Za-z0-9_]*)/', $value, $matches);

    $names = array_values(array_unique($matches[1]));
    sort($names);

    return $names;
}

/**
 * Why the Arabic value's placeholders are not the English value's, or null.
 */
function catalogueParityPlaceholderProblem(string $english, string $arabic): ?string
{
    $expected = catalogueParityPlaceholders($english);
    $actual = catalogueParityPlaceholders($arabic);

    if ($expected === $actual) {
        return null;
    }

    $dropped = array_values(array_diff($expected, $actual));
    $added = array_values(array_diff($actual, $expected));

    return trim(
        ($dropped === [] ? '' : 'drops :'.implode(', :', $dropped).'. ')
        .($added === [] ? '' : 'adds :'.implode(', :', $added).'.'),
    );
}

/**
 * Is this value written as Laravel's plural lines are: segments joined by a `|`
 * that has text on both sides? "x | y" (a separator with spaces, as in
 * `:page | :name`) is a divider and not a plural.
 */
function catalogueParityIsPlural(string $value): bool
{
    return preg_match('/\S\|\S/u', $value) === 1;
}

/**
 * Does the value say anything once its placeholders are taken out? `© :year :name`
 * and `:page | :name` do not: there is nothing in them to translate.
 */
function catalogueParityHasWords(string $value): bool
{
    return preg_match('/\p{L}/u', (string) preg_replace('/:[A-Za-z_][A-Za-z0-9_]*/', '', $value)) === 1;
}

/**
 * Why the Arabic value is not filled in, or null.
 *
 * @param  array<string, string>  $identical  the keys allowed to equal their English
 */
function catalogueParityFillProblem(string $key, string $english, mixed $arabic, array $identical): ?string
{
    if (! is_string($arabic) || trim($arabic) === '') {
        return 'is empty';
    }

    if (array_key_exists($key, $identical)) {
        return $arabic === $english ? null : 'is allowlisted as identical to the English but differs from it';
    }

    if (! catalogueParityHasWords($english)) {
        return null;
    }

    if (preg_match('/\p{Arabic}/u', $arabic) !== 1) {
        return 'has no Arabic script';
    }

    return null;
}

/**
 * Why the Arabic value is not a correct plural for its English counterpart, or an
 * empty list.
 *
 * Only plain segments are accepted: six of them, in Laravel's order. Explicit
 * ranges (`[3,10]`) would be tested against the whole count and not `count % 100`,
 * so they would put 103 in the wrong form.
 *
 * @return list<string>
 */
function catalogueParityPluralProblems(string $english, string $arabic): array
{
    $englishPlural = catalogueParityIsPlural($english);

    if ($englishPlural !== catalogueParityIsPlural($arabic)) {
        return [$englishPlural
            ? 'is pluralised in English but is a single form in Arabic'
            : 'is a single form in English but has a plural separator in Arabic'];
    }

    if (! $englishPlural) {
        return [];
    }

    $segments = explode('|', $arabic);

    if (count($segments) !== 6) {
        return ['has '.count($segments).' plural form(s), and Arabic needs six: zero, one, two, few, many, other'];
    }

    $problems = [];
    $expected = array_values(array_diff(catalogueParityPlaceholders($english), ['count']));

    foreach ($segments as $form => $segment) {
        if (trim($segment) === '') {
            $problems[] = "form {$form} is empty";

            continue;
        }

        if (preg_match('/\p{Arabic}/u', $segment) !== 1) {
            $problems[] = "form {$form} has no Arabic script";
        }

        // :count may be left out of a form that says the number in words ("منشور
        // واحد"); any other placeholder may not, or that count loses its topic.
        if (array_values(array_diff(catalogueParityPlaceholders($segment), ['count'])) !== $expected) {
            $problems[] = "form {$form} does not carry exactly the placeholders :".implode(', :', $expected);
        }
    }

    $selector = new MessageSelector;

    foreach (CATALOGUE_PARITY_ARABIC_FORMS as $count => $form) {
        $chosen = $selector->choose($arabic, $count, 'ar');

        if ($chosen !== $segments[$form]) {
            $problems[] = "a count of {$count} should use form {$form}, but Laravel chose: {$chosen}";
        }
    }

    return $problems;
}

/**
 * The translator keys of every pluralised English value, across the catalogues.
 *
 * @return list<string>
 */
function catalogueParityPluralKeys(): array
{
    $keys = [];

    foreach (catalogueParityNames() as $name) {
        foreach (catalogueParityPairs($name) as $key => $pair) {
            if (is_string($pair['english']) && catalogueParityIsPlural($pair['english'])) {
                $keys[] = $key;
            }
        }
    }

    return $keys;
}

/*
|--------------------------------------------------------------------------
| The real catalogues
|--------------------------------------------------------------------------
*/

it('reads a meaningful number of English keys', function () {
    // A read that matches nothing makes every check below pass over an empty set.
    $total = 0;

    foreach (catalogueParityNames() as $name) {
        $total += count(catalogueParityPairs($name));
    }

    expect($total)->toBeGreaterThan(150);
});

it('gives the Arabic catalogue exactly the English keys', function (string $name) {
    $difference = catalogueParityKeyDifferences(catalogueParityFile('en', $name), catalogueParityFile('ar', $name));

    expect($difference['missing'])->toBe([], "lang/ar/{$name}.php lacks: ".implode(', ', $difference['missing']))
        ->and($difference['extra'])->toBe([], "lang/ar/{$name}.php has keys the English file lacks: ".implode(', ', $difference['extra']));
})->with(catalogueParityNames());

it('keeps every placeholder of every value', function (string $name) {
    $problems = [];

    foreach (catalogueParityPairs($name) as $key => $pair) {
        if ($pair['arabic'] === null) {
            continue;
        }

        $problem = catalogueParityPlaceholderProblem((string) $pair['english'], (string) $pair['arabic']);

        if ($problem !== null) {
            $problems[] = "{$key} {$problem}";
        }
    }

    expect($problems)->toBe([], implode("\n", $problems));
})->with(catalogueParityNames());

it('keeps a plural a plural, in all six Arabic forms', function (string $name) {
    $problems = [];

    foreach (catalogueParityPairs($name) as $key => $pair) {
        if (! is_string($pair['arabic'])) {
            continue;
        }

        foreach (catalogueParityPluralProblems((string) $pair['english'], $pair['arabic']) as $problem) {
            $problems[] = "{$key} {$problem}";
        }
    }

    expect($problems)->toBe([], implode("\n", $problems));
})->with(catalogueParityNames());

it('fills every value with Arabic, except the few that are the same by design', function (string $name) {
    $problems = [];

    foreach (catalogueParityPairs($name) as $key => $pair) {
        $problem = catalogueParityFillProblem($key, (string) $pair['english'], $pair['arabic'], CATALOGUE_PARITY_IDENTICAL);

        if ($problem !== null) {
            $problems[] = "{$key} {$problem}";
        }
    }

    expect($problems)->toBe([], implode("\n", $problems));
})->with(catalogueParityNames());

it('lists only allowlisted keys that still exist and are still identical', function () {
    $pairs = [];

    foreach (catalogueParityNames() as $name) {
        $pairs += catalogueParityPairs($name);
    }

    $stale = [];

    foreach (CATALOGUE_PARITY_IDENTICAL as $key => $reason) {
        if (! array_key_exists($key, $pairs)) {
            $stale[] = "{$key} is allowlisted but no English key has that name";
        } elseif ($pairs[$key]['arabic'] !== $pairs[$key]['english']) {
            $stale[] = "{$key} is allowlisted as identical, and its Arabic now differs; remove the entry";
        }

        if (trim($reason) === '') {
            $stale[] = "{$key} is allowlisted without a reason";
        }
    }

    expect($stale)->toBe([], implode("\n", $stale));
});

it('finds the pluralised values, so the plural checks have something to check', function () {
    // The five publication counts and the download line from the library, and the
    // hours line from the programme card. A detector gone blind passes every
    // plural test with nothing to look at.
    expect(catalogueParityPluralKeys())->toEqualCanonicalizing([
        'public.programmes.hours',
        'publications.public.count',
        'publications.public.count_in_topic',
        'publications.public.count_matching',
        'publications.public.count_in_topic_matching',
        'publications.public.downloads',
    ]);
});

it('selects the right Arabic form through trans_choice for a count from each range', function () {
    $replace = ['topic' => 'TOPIC-VALUE', 'search' => 'SEARCH-VALUE'];

    foreach (catalogueParityNames() as $name) {
        foreach (catalogueParityPairs($name) as $key => $pair) {
            if (! is_string($pair['english']) || ! catalogueParityIsPlural($pair['english'])) {
                continue;
            }

            $segments = explode('|', (string) $pair['arabic']);

            foreach (CATALOGUE_PARITY_ARABIC_FORMS as $count => $form) {
                // The expected line: the form the CLDR rule gives, with each
                // placeholder filled in by hand.
                $expected = str_replace(
                    [':count', ':topic', ':search'],
                    [(string) $count, 'TOPIC-VALUE', 'SEARCH-VALUE'],
                    $segments[$form] ?? '',
                );

                expect(trans_choice($key, $count, $replace, 'ar'))->toBe($expected, "{$key} for a count of {$count}");
            }
        }
    }
});

/*
|--------------------------------------------------------------------------
| The checkers' self-tests: each must fail a planted sample
|--------------------------------------------------------------------------
*/

it('finds a key the Arabic catalogue lacks, at any depth', function () {
    $english = ['title' => 'Title', 'home' => ['heading' => 'Heading', 'lead' => 'Lead']];

    expect(catalogueParityKeyDifferences($english, ['title' => 'عنوان', 'home' => ['heading' => 'ترويسة']]))
        ->toBe(['missing' => ['home.lead'], 'extra' => []])
        ->and(catalogueParityKeyDifferences($english, ['title' => 'عنوان']))
        ->toBe(['missing' => ['home.heading', 'home.lead'], 'extra' => []]);
});

it('finds a key only the Arabic catalogue has', function () {
    $english = ['title' => 'Title'];

    expect(catalogueParityKeyDifferences($english, ['title' => 'عنوان', 'subtitle' => 'عنوان فرعي']))
        ->toBe(['missing' => [], 'extra' => ['subtitle']]);
});

it('finds a leaf that became a group, or a group that became a leaf', function () {
    expect(catalogueParityKeyDifferences(['home' => ['heading' => 'Heading']], ['home' => 'الرئيسية']))
        ->toBe(['missing' => ['home.heading'], 'extra' => ['home']]);
});

it('accepts catalogues with the same keys', function () {
    $english = ['title' => 'Title', 'home' => ['heading' => 'Heading']];

    expect(catalogueParityKeyDifferences($english, ['home' => ['heading' => 'ترويسة'], 'title' => 'عنوان']))
        ->toBe(['missing' => [], 'extra' => []]);
});

it('finds a dropped, an added and a renamed placeholder', function (string $english, string $arabic) {
    expect(catalogueParityPlaceholderProblem($english, $arabic))->not->toBeNull();
})->with([
    'dropped' => ['Starts :date', 'يبدأ قريبًا'],
    'added' => ['Starts soon', 'يبدأ في :date'],
    'renamed' => ['Starts :date', 'يبدأ في :day'],
    'translated' => ['Page :current of :last', 'الصفحة :الحالية من :last'],
    'one of two dropped' => ['Page :current of :last', 'الصفحة :current'],
]);

it('lets a placeholder move and does not mistake punctuation for one', function () {
    expect(catalogueParityPlaceholderProblem('Page :current of :last', 'من :last الصفحة :current'))->toBeNull()
        ->and(catalogueParityPlaceholderProblem('Download PDF: :title', 'تنزيل PDF: :title'))->toBeNull()
        ->and(catalogueParityPlaceholderProblem('e.g. TC-2026-7K4M9Q2R', 'مثال: TC-2026-7K4M9Q2R'))->toBeNull();
});

it('says which placeholder was dropped', function () {
    expect(catalogueParityPlaceholderProblem(':count item in :topic', ':count عنصر'))->toContain(':topic');
});

it('finds an unpluralised plural', function (string $english, string $arabic) {
    expect(catalogueParityPluralProblems($english, $arabic))->not->toBe([]);
})->with([
    'a single form' => [':count hour|:count hours', ':count ساعة'],
    'the English two forms copied across' => [':count hour|:count hours', ':count ساعة|:count ساعات'],
    'five forms' => [':count hour|:count hours', 'ساعة واحدة|ساعتان|:count ساعات|:count ساعة|:count ساعة'],
    'seven forms' => [':count hour|:count hours', 'a|b|c|d|e|f|g'],
    'a plural separator where English has none' => ['Download', 'تنزيل|تنزيلات'],
    'an empty form' => [':count hour|:count hours', ':count ساعة|ساعة واحدة||:count ساعات|:count ساعة|:count ساعة'],
    'a Latin form' => [':count hour|:count hours', ':count ساعة|ساعة واحدة|ساعتان|:count hours|:count ساعة|:count ساعة'],
    'explicit ranges, which put 103 in the wrong form' => [
        ':count hour|:count hours',
        '{0}لا ساعات|{1}ساعة واحدة|{2}ساعتان|[3,10]:count ساعات|[11,99]:count ساعة|[100,*]:count ساعة',
    ],
    'a form that drops the topic' => [
        ':count item in :topic|:count items in :topic',
        'لا عناصر في :topic|عنصر واحد في :topic|عنصران في :topic|:count عناصر في :topic|:count عنصرًا|:count عنصر في :topic',
    ],
]);

it('accepts a plural that has all six Arabic forms', function () {
    $arabic = 'لا ساعات|ساعة واحدة|ساعتان|:count ساعات|:count ساعة|:count ساعة';

    expect(catalogueParityPluralProblems(':count hour|:count hours', $arabic))->toBe([])
        ->and(catalogueParityPluralProblems(':count item in :topic|:count items in :topic', 'لا عناصر في :topic|عنصر واحد في :topic|عنصران في :topic|:count عناصر في :topic|:count عنصرًا في :topic|:count عنصر في :topic'))->toBe([]);
});

it('does not take a divider for a plural', function () {
    expect(catalogueParityIsPlural(':page | :name'))->toBeFalse()
        ->and(catalogueParityIsPlural(':count hour|:count hours'))->toBeTrue()
        ->and(catalogueParityPluralProblems(':page | :name', ':page | :name'))->toBe([]);
});

it('finds an empty value, an untranslated one and one in the wrong script', function (mixed $arabic) {
    expect(catalogueParityFillProblem('x.y', 'Read more', $arabic, []))->not->toBeNull();
})->with([
    'empty' => [''],
    'whitespace' => ["  \n"],
    'null' => [null],
    'a group where a string belongs' => [['a' => 'b']],
    'the English left in place' => ['Read more'],
    'Latin text of other words' => ['Lire la suite'],
]);

it('accepts a filled value, a wordless one and an allowlisted identical one', function () {
    expect(catalogueParityFillProblem('x.y', 'Read more', 'قراءة المزيد', []))->toBeNull()
        ->and(catalogueParityFillProblem('x.y', 'Download PDF', 'تنزيل PDF', []))->toBeNull()
        ->and(catalogueParityFillProblem('x.copyright', '© :year :name', '© :year :name', []))->toBeNull()
        ->and(catalogueParityFillProblem('x.format', 'd M Y', 'd M Y', ['x.format' => 'a date pattern']))->toBeNull();
});

it('refuses an allowlisted key whose Arabic is not the English, and an empty one', function () {
    expect(catalogueParityFillProblem('x.format', 'd M Y', 'j F Y', ['x.format' => 'a date pattern']))->not->toBeNull()
        ->and(catalogueParityFillProblem('x.format', 'd M Y', '', ['x.format' => 'a date pattern']))->not->toBeNull();
});

it('sees a word only where there is one', function () {
    expect(catalogueParityHasWords('© :year :name'))->toBeFalse()
        ->and(catalogueParityHasWords(':page | :name'))->toBeFalse()
        ->and(catalogueParityHasWords(':name'))->toBeFalse()
        ->and(catalogueParityHasWords('Starts :date'))->toBeTrue()
        ->and(catalogueParityHasWords('PDF'))->toBeTrue();
});
