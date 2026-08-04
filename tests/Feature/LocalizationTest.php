<?php

declare(strict_types=1);

use App\Domain\Enrollment\Enums\BatchStatus;
use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Enrollment\Enums\StudentStatus;
use App\Domain\Staff\Enums\EmploymentType;
use App\Domain\Staff\Support\ActivityEvent;
use App\Http\Middleware\SetLocale;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Translation catalogue and locale enforcement (P1-T14)
|--------------------------------------------------------------------------
|
| WHAT THIS FILE IS ACTUALLY FOR.
|
| Every user-facing string in this codebase has gone through __() since commit
| one, and the catalogue backing it did not exist. A missing key is not an
| error in Laravel — the translator returns the key itself — so for eleven
| tasks the panel rendered "enrollment.student" where it meant "Student", every
| test passed, and nothing anywhere said so.
|
| That is the failure this file exists to make loud. The completeness test
| below is as much the deliverable as the strings are: without it, key 136 goes
| missing exactly the same silent way.
*/

beforeEach(function () {
    $this->seed(RolePermissionSeeder::class);
});

/**
 * Every literal `__('group.key')` in the application's own source.
 *
 * PHP is scanned with comments stripped, because the comments in this codebase
 * quote translation keys while explaining them. Blade is scanned raw: it is not
 * tokenisable as PHP, and its `{{-- --}}` comments do not quote keys.
 *
 * Namespaced package keys (`filament-panels::layout.direction`) do not match
 * and are not meant to: the group there is a package's, not ours, and the
 * package ships its own catalogue.
 *
 * @return array<string, string> key => the file it was found in
 */
function referencedTranslationKeys(): array
{
    $roots = array_filter([app_path(), resource_path('views'), base_path('routes'), base_path('database')], 'is_dir');

    $found = [];

    foreach ($roots as $root) {
        foreach (File::allFiles($root) as $file) {
            $path = (string) $file->getRealPath();

            $source = str_ends_with($path, '.blade.php')
                ? (string) file_get_contents($path)
                : ($file->getExtension() === 'php' ? appSourceWithoutComments($path) : null);

            if ($source === null) {
                continue;
            }

            // BOTH quote styles. A single-quote-only pattern is fail-open: PHP
            // treats "staff.name" and 'staff.name' identically, so a key written
            // with double quotes — as every interpolated key in this codebase
            // is — was invisible to the scan and silently uncounted.
            preg_match_all('/__\(\s*[\'"]([a-z_]+\.[a-zA-Z0-9_.]+)[\'"]/', $source, $matches);

            foreach ($matches[1] as $key) {
                $found[$key] ??= str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
            }
        }
    }

    ksort($found);

    return $found;
}

/*
|--------------------------------------------------------------------------
| The catalogue
|--------------------------------------------------------------------------
*/

it('resolves every translation key the application references', function () {
    $missing = [];

    foreach (referencedTranslationKeys() as $key => $file) {
        $value = __($key);

        // An EMPTY value is the other way this fails open. '' is not the key,
        // so a resolves-to-itself check passes it, and the panel renders a
        // blank label — which reads as a styling bug, not a missing string, and
        // is harder to trace than the raw key would have been. Whitespace-only
        // is the same thing wearing a disguise.
        if ($value === $key || (is_string($value) && trim($value) === '')) {
            $missing[] = "{$key}  ({$file})".($value === $key ? '' : '  [empty]');
        }
    }

    expect($missing)->toBeEmpty(
        count($missing).' translation key(s) resolve to themselves or to nothing, so the panel '
        ."renders the key, or a blank, where it means the label:\n  ".implode("\n  ", $missing),
    );
});

it('resolves every referenced key to a string, never to a group of keys', function () {
    // A nested group and a leaf cannot share a name. __('staff.employment_type')
    // returned the three employment-type CASES once this catalogue existed, and
    // Filament's ->label() takes string|Closure|Htmlable — so the staff register
    // fatalled with a TypeError rather than showing a wrong label.
    //
    // Nothing about that is visible in the lang file, in the call site, or in
    // any test that does not render the page. It is visible here.
    $arrays = [];

    foreach (referencedTranslationKeys() as $key => $file) {
        if (is_array(__($key))) {
            $arrays[] = "{$key}  ({$file})";
        }
    }

    expect($arrays)->toBeEmpty(
        "These keys name a group of translations but are used as a single string:\n  "
        .implode("\n  ", $arrays)
        ."\nRename one of the two — the convention here is a plural group ('employment_types') "
        ."beside the singular label ('employment_type').",
    );
});

it('detects a missing key, so the completeness test above means something', function () {
    // The premise the test above rests on: an absent key comes back as itself
    // rather than as null or an exception. If Laravel ever stopped doing that,
    // the completeness test would pass unconditionally and silently.
    expect(__('enrollment.no_such_key_exists'))->toBe('enrollment.no_such_key_exists');
});

it('scans a meaningful number of files', function () {
    // A scan that silently matches nothing passes every assertion built on it.
    // The floor is deliberately far below the real count (160 at the time of
    // writing); it catches a broken root path or regex, not a shrinking app.
    expect(count(referencedTranslationKeys()))->toBeGreaterThan(100);
});

it('keeps the framework auth lines that this file does not restate', function () {
    // lang/en/auth.php holds four keys and MERGES with the framework's own
    // auth.php rather than replacing it. Turning that file into a plain
    // `return [...]` of everything would break the login failure message.
    expect(__('auth.failed'))->not->toBe('auth.failed')
        ->and(__('auth.throttle'))->not->toBe('auth.throttle')
        ->and(__('auth.new_password'))->toBe('New password');
});

/*
|--------------------------------------------------------------------------
| Keys built by interpolation
|--------------------------------------------------------------------------
|
| The scan above sees literal keys only. Every enum label builds its key from
| the case value — "enrollment.batch_status.{$this->value}" — so a case added
| without a matching line is invisible to it. These walk the cases themselves.
*/

it('translates every enum case', function (string $enum) {
    $unlabelled = [];

    foreach ($enum::cases() as $case) {
        $label = $case->label();

        // Two failures to catch, not one. A missing key surfaces as the key
        // itself; BatchStatus, StudentStatus and EmploymentType additionally
        // fall back to the raw case value, which reads like a label ("active")
        // and is exactly how this goes unnoticed.
        if (str_contains($label, '.') || $label === $case->value) {
            $unlabelled[] = "{$case->value} => {$label}";
        }
    }

    expect($unlabelled)->toBeEmpty(
        $enum.' has case(s) with no translation: '.implode(', ', $unlabelled),
    );
})->with([
    BatchStatus::class,
    StudentStatus::class,
    EnrollmentStatus::class,
    EmploymentType::class,
]);

/*
|--------------------------------------------------------------------------
| The activity log's interpolated keys (P1-T15, group 3 finding M5)
|--------------------------------------------------------------------------
|
| These were outside every completeness check, and their fallbacks hid it.
| ActivityResource::eventLabel() returns the RAW EVENT when the key is missing,
| so a forgotten translation renders as `deleted_by_cascade` — which reads like a
| deliberate technical label rather than a gap. Proven by mutation before the
| fix: deleting activity.event.deleted_by_cascade failed nothing.
|
| The literal scan above cannot reach them because the keys are built by
| interpolation, and a regex over `->event('…')` cannot learn the vocabulary
| either: two call sites pass a variable. ActivityEvent declares it instead, and
| these walk that declaration — forward, reverse, and at the boundary.
*/

it('translates every event the activity log can record', function () {
    /*
     * Collected into one list rather than run as a dataset, matching every other
     * completeness check in this file: a single failure then names all the
     * missing keys at once instead of stopping at the first.
     */
    $unlabelled = [];

    foreach (ActivityEvent::all() as $event) {
        $key = "activity.event.{$event}";
        $label = __($key);

        // Two failures to catch. A missing key surfaces as the key itself; a
        // blank value is not the key, so a presence-only check would let an
        // empty label through and it would read as a styling bug.
        if ($label === $key || trim((string) $label) === '') {
            $unlabelled[] = $event;
        }
    }

    expect($unlabelled)->toBeEmpty(
        'These events have no label, and eventLabel() falls back to the raw event so each '
        .'renders as a plausible technical string rather than a visible gap: '
        .implode(', ', $unlabelled),
    );
});

it('translates every record type the log can show', function () {
    /*
     * The other interpolated lookup. recordTypeLabel() keys on the class
     * basename and falls back to it, so a missing entry shows "StaffCertificate"
     * — an English class name, in front of an Arabic reader in phase 4.
     *
     * Derived from the models that actually record activity rather than from a
     * hand-written list, for the reason finding L4 established.
     */
    $models = recordsActivityModels();

    // The scan must have found something, or the loop below asserts nothing.
    expect($models)->not->toBeEmpty('No model was found using RecordsActivity.');

    $unlabelled = [];

    foreach ($models as $model) {
        $basename = class_basename($model);
        $key = "activity.record_type.{$basename}";
        $label = __($key);

        if ($label === $key || trim((string) $label) === '') {
            $unlabelled[] = $basename;
        }
    }

    expect($unlabelled)->toBeEmpty(
        'These record types have no label, so the log shows a raw class name: '
        .implode(', ', $unlabelled),
    );
});

it('has no event label the application can never produce', function () {
    /*
     * The reverse direction, and it is not pedantry: a key nothing emits is
     * either a rename that left its old label behind or an event somebody
     * removed, and both mislead the next person deciding whether a label is
     * still needed. Keeping the catalogue honest in both directions is what
     * makes "every event is translated" a statement about the application
     * rather than about the lang file.
     */
    $catalogue = array_keys((array) __('activity.event'));
    $orphans = array_diff($catalogue, ActivityEvent::all());

    expect($orphans)->toBeEmpty(
        'These activity.event.* labels correspond to no event in ActivityEvent: '
        .implode(', ', $orphans),
    );
});

it('routes every explicit activity write through the declared vocabulary', function () {
    /*
     * THE BOUNDARY, without which the registry is decorative.
     *
     * ActivityEvent only describes the vocabulary if every writer uses it. A
     * future `->event('new_event')` would bypass the constant list entirely, and
     * the two checks above would keep passing while an unlabelled event reached
     * an administrator — the exact failure M5 is about, reintroduced one call
     * site at a time.
     *
     * So a literal is refused at the call. This is the one place a source scan
     * IS the right tool: it is asking what the code says, not what it does, and
     * the answer is a syntactic fact.
     */
    $offenders = [];

    foreach (File::allFiles(app_path()) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $path = (string) $file->getRealPath();
        $source = appSourceWithoutComments($path);

        // ->event('literal') or ->log('literal') — either bypasses the registry.
        if (preg_match('/->(event|log)\s*\(\s*[\'"]/', $source, $match) === 1) {
            $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path)
                .' → '.trim($match[0]);
        }
    }

    expect($offenders)->toBeEmpty(
        'Activity events must come from ActivityEvent, or the completeness checks above stop '
        ."describing the application:\n  ".implode("\n  ", $offenders),
    );
});

/*
|--------------------------------------------------------------------------
| Composite strings
|--------------------------------------------------------------------------
*/

/*
 * That the CALL SITES honour these formats is already proven where it can be
 * proven properly — EnrollmentsRelationManagerTest renders the relation manager
 * with sentinel translations in place, so a hardcoded separator or a fixed
 * ordering fails there. Repeating a sentinel round-trip here would assert
 * Laravel's own replacement logic and catch nothing in this codebase.
 *
 * What is left to guard is the catalogue side: a format that loses its
 * placeholders becomes untranslatable no matter how correct the call site is.
 */

it('keeps named placeholders in every composite format', function () {
    $formats = [
        'enrollment.student_option_label' => [':code', ':name'],
        'enrollment.enrolment_load_value' => [':active', ':capacity'],
    ];

    foreach ($formats as $key => $placeholders) {
        foreach ($placeholders as $placeholder) {
            // str_contains rather than expect()->toContain(), which is variadic:
            // a second argument there reads as another expected value, not as a
            // failure message, and the assertion silently becomes stricter.
            expect(str_contains((string) __($key), $placeholder))->toBeTrue(
                "{$key} lost {$placeholder}. Positional or removed placeholders make the string "
                .'untranslatable: the parts can no longer be reordered.',
            );
        }
    }
});

/*
|--------------------------------------------------------------------------
| Arabic: present, empty, and falling back
|--------------------------------------------------------------------------
*/

it('ships an arabic file for every english one', function () {
    $english = collect(File::files(lang_path('en')))
        ->map(fn ($file): string => $file->getFilename())
        ->sort()
        ->values()
        ->all();

    $arabic = collect(File::files(lang_path('ar')))
        ->map(fn ($file): string => $file->getFilename())
        ->sort()
        ->values()
        ->all();

    expect($arabic)->toBe($english);
});

it('keeps the arabic catalogues empty until phase 4', function (string $file) {
    // Deliberate, and asserted so that nobody "helpfully" fills these with the
    // English strings. Copied English would render identically to a real
    // translation, so no screenshot, review or test could tell how much of the
    // panel a translator had actually reached. Absent means untranslated.
    expect(require lang_path("ar/{$file}.php"))->toBe([]);
})->with(['activity', 'auth', 'enrollment', 'staff']);

it('falls back to english for a key arabic does not have', function () {
    app()->setLocale('ar');

    expect(__('staff.name'))->toBe('Name')
        ->and(__('enrollment.student'))->toBe('Student')
        ->and(EnrollmentStatus::Withdrawn->label())->toBe('Withdrawn');
});

it('uses an arabic line when one exists', function () {
    // The other half of the fallback: proof the Arabic file is actually
    // consulted, not merely present. Without this, an unregistered lang path
    // would look identical to a working one.
    app('translator')->addLines(['staff.name' => 'الاسم'], 'ar');
    app()->setLocale('ar');

    expect(__('staff.name'))->toBe('الاسم');
});

/*
|--------------------------------------------------------------------------
| Locale enforcement
|--------------------------------------------------------------------------
*/

it('applies the signed-in user locale', function () {
    $user = User::factory()->create(['is_active' => true, 'locale' => 'ar']);
    $user->assignRole('admin');

    $this->actingAs($user)->get('/admin')->assertSuccessful();

    expect(app()->getLocale())->toBe('ar');
});

it('resets an unsupported stored locale to the fallback', function () {
    // locale is a plain string(5) column: a hand-edited row, a restored dump or
    // a locale withdrawn in a later release all put a value here that this
    // application cannot render.
    $user = User::factory()->create(['is_active' => true, 'locale' => 'fr']);
    $user->assignRole('admin');

    app()->setLocale('ar');

    $this->actingAs($user)->get('/admin')->assertSuccessful();

    // Not merely "is not fr". Leaving the previous locale standing is the bug:
    // in a long-lived worker that is the last request's language, so one user's
    // page would render in another user's.
    expect(app()->getLocale())->toBe('en');
});

it('leaves a guest on the fallback locale', function () {
    $this->get('/admin/login')->assertSuccessful();

    expect(app()->getLocale())->toBe('en');
});

it('registers the locale middleware after authentication', function () {
    $middleware = Filament::getPanel('admin')->getAuthMiddleware();

    expect($middleware)->toContain(SetLocale::class);

    $positionOf = fn (string $class): int|false => array_search($class, array_values($middleware), true);

    expect($positionOf(SetLocale::class))->toBeGreaterThan(
        $positionOf(Authenticate::class),
        'SetLocale reads users.locale, so it must run after Filament has resolved the user.',
    );
});

it('keeps the locale middleware across livewire updates', function () {
    // Table filters, modals and saves arrive on Livewire's own route, where
    // panel route middleware does not run. Without persistence the first paint
    // is Arabic and every interaction after it English.
    expect(Livewire::getPersistentMiddleware())->toContain(SetLocale::class);
});

/*
|--------------------------------------------------------------------------
| Direction
|--------------------------------------------------------------------------
*/

it('renders the panel right-to-left for an arabic user', function () {
    $user = User::factory()->create(['is_active' => true, 'locale' => 'ar']);
    $user->assignRole('admin');

    // The rendered attribute, not the config that ought to produce it. Filament
    // sets <html dir> from __('filament-panels::layout.direction'), which it
    // ships as 'rtl' for ar — so this asserts the whole chain works: the
    // middleware set the locale, and the locale reached the layout.
    $this->actingAs($user)->get('/admin')->assertSee('dir="rtl"', escape: false);
});

it('renders the panel left-to-right for an english user', function () {
    $user = User::factory()->create(['is_active' => true, 'locale' => 'en']);
    $user->assignRole('admin');

    $this->actingAs($user)->get('/admin')
        ->assertSee('dir="ltr"', escape: false)
        ->assertDontSee('dir="rtl"', escape: false);
});

/*
|--------------------------------------------------------------------------
| Logical CSS
|--------------------------------------------------------------------------
|
| Direction only reaches the page if the styling follows it. `margin-left` is
| still on the left in Arabic; `margin-inline-start` moves. The panel renders
| dir="rtl" today, so this is enforceable now rather than a phase-4 promise.
*/

/**
 * Laravel's stock welcome page, which ships a COMPILED Tailwind v4 build inlined
 * into a <style> block. Scanning a generated vendor artifact measures Tailwind's
 * output, not this project's discipline.
 *
 * The exclusion is conditional, not permanent: the test below re-checks that the
 * file still carries that build. Whoever replaces this placeholder with a real
 * public site loses the exemption automatically rather than inheriting it.
 */
const STOCK_TAILWIND_PAGES = ['welcome.blade.php'];

it('only exempts files that are still stock vendor builds', function () {
    foreach (STOCK_TAILWIND_PAGES as $name) {
        $path = resource_path("views/{$name}");

        expect(file_exists($path))->toBeTrue("{$name} is exempted from the logical-CSS scan but no longer exists. Remove the exemption.");

        // str_contains, not expect()->toContain(): that is variadic, so a second
        // argument becomes a second expected substring rather than the failure
        // message — and the failure then dumps the whole 220-line file.
        expect(str_contains((string) file_get_contents($path), 'tailwindcss v4'))->toBeTrue(
            "{$name} no longer contains the generated Tailwind build it was exempted for. It is hand-written now, so it must be scanned.",
        );
    }
});

/**
 * The first physical, direction-blind CSS construct in $source, or null.
 *
 * A named function rather than an inline pattern so the detector can be tested
 * against samples. A scan that quietly matches less than it claims is worse than
 * no scan: the README promises a rule, the suite reports it enforced, and
 * `ml-auto` sails through. The self-tests below are what make the promise real.
 */
/**
 * Does this source contain a margin/padding/inset shorthand with three or four
 * values — the forms that name left and right without ever writing the words?
 *
 * COUNTS TOP-LEVEL VALUES, NOT SPACES (P1-T15, review of finding L2). The first
 * version was a regex requiring two internal runs of whitespace, which is not
 * the same question: `margin: calc(100% - 1rem) auto` is two values containing
 * four spaces, and was wrongly flagged. Splitting on whitespace at parenthesis
 * depth zero asks what CSS actually means.
 *
 * One and two values are symmetric — `margin: 0`, `margin: 0 auto` — and flip
 * harmlessly. Three and four are top/right/bottom/left and do not.
 */
function hasDirectionalShorthand(string $source): bool
{
    if (preg_match_all('/(?<![\w-])(margin|padding|inset)\s*:([^;{}]*)/i', $source, $matches, PREG_SET_ORDER) === 0) {
        return false;
    }

    foreach ($matches as $match) {
        if (countTopLevelCssValues($match[2]) >= 3) {
            return true;
        }
    }

    return false;
}

/**
 * How many space-separated values a CSS declaration body holds, treating
 * anything inside parentheses as one value.
 *
 * `calc(100% - 1rem) auto` is two, not four. `var(--spacing, 1rem 2rem)` is one,
 * not three — the commas and spaces belong to the function call, and whether the
 * variable itself expands to something directional is not knowable here.
 */
function countTopLevelCssValues(string $body): int
{
    $depth = 0;
    $values = 0;
    $inValue = false;

    foreach (str_split(trim($body)) as $character) {
        if ($character === '(') {
            $depth++;
        } elseif ($character === ')') {
            $depth = max(0, $depth - 1);
        }

        $isSeparator = $depth === 0 && ($character === ' ' || $character === "\t" || $character === "\n");

        if ($isSeparator) {
            $inValue = false;

            continue;
        }

        if (! $inValue) {
            $inValue = true;
            $values++;
        }
    }

    return $values;
}

function firstPhysicalCssProperty(string $source): ?string
{
    // A Tailwind spacing/inset suffix: numeric (4, 1.5), px, auto, full, or an
    // arbitrary value. Bare `ml-` with nothing after it is not a class.
    $suffix = '(\d+(\.\d+)?|px|auto|full|screen|\[[^\]]+\])';

    $patterns = [
        // --- raw CSS declarations ---
        '/(margin|padding)-(left|right)\s*:/i',
        '/border-(left|right)(-(width|style|color|radius))?\s*:/i',
        '/text-align\s*:\s*(left|right)/i',
        '/float\s*:\s*(left|right)/i',
        '/clear\s*:\s*(left|right)/i',
        /*
         * Bare `left:` / `right:` in declaration position — after a brace, a
         * semicolon, a quote, or at the start of a line. Unanchored, this would
         * fire on any JavaScript object literal in a Blade template.
         *
         * The quote branch is what reaches style="left: 0", where the property
         * opens the attribute and no brace or semicolon precedes it. It does not
         * reach the JSON key `{"left": 0}`, because there the name is CLOSED by
         * a quote before the colon — `left"` rather than `left:` — so requiring
         * the colon to follow the name is what separates the two.
         */
        '/(?<=[{;"\']|^)\s*(left|right)\s*:/im',

        // --- Tailwind utilities, including the negative and arbitrary forms ---
        '/(?<![\w-])-?(ml|mr|pl|pr)-'.$suffix.'/i',        // -ml-2, ml-auto, ml-[3px]
        '/(?<![\w-])-?(left|right)-'.$suffix.'/i',         // left-0, -right-4, left-[1rem]
        '/(?<![\w-])text-(left|right)(?![\w-])/i',
        '/(?<![\w-])float-(left|right)(?![\w-])/i',
        /*
         * border-l / border-r and every suffix they take: border-l, border-r-2,
         * border-l-[3px], border-l-red-500.
         *
         * The trailing (?![\w]) rejects a letter but allows a hyphen, which is
         * what separates the utility from the colour: `border-r-2` continues
         * with `-`, while `border-red-500` continues with `e` and is left alone.
         * `border-s` / `border-e` never enter the alternation at all.
         */
        '/(?<![\w-])border-(l|r)(?![\w])/i',

        /*
         * CORNER RADIUS, both spellings (P1-T15, finding L2). A corner is
         * physical in exactly the way a side is: `rounded-tl-lg` stays on the
         * visual left when the page flips, so a card's cut corner ends up on the
         * wrong side in Arabic. The logical forms are rounded-ss/se/es/ee and
         * border-start-start-radius.
         */
        '/border-(top|bottom)-(left|right)-radius\s*:/i',
        '/(?<![\w-])rounded-(tl|tr|bl|br|l|r)(?![\w])/i',

        /*
         * DIRECTIONAL SHORTHAND is handled below rather than here, because it
         * needs to COUNT VALUES and a regex can only count spaces. See
         * hasDirectionalShorthand().
         */
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $source, $match)) {
            return trim($match[0]);
        }
    }

    if (hasDirectionalShorthand($source)) {
        return 'directional margin/padding/inset shorthand';
    }

    return null;
}

it('catches every physical form the stylesheet rules forbid', function (string $sample) {
    expect(firstPhysicalCssProperty($sample))->not->toBeNull(
        "The logical-CSS detector missed: {$sample}",
    );
})->with([
    // Raw CSS.
    'margin-left: 1rem;',
    'margin-right:0',
    'padding-left: 2px;',
    'padding-right : 2px;',
    'border-left: 1px solid red;',
    'border-right-width: 2px;',
    'text-align: left;',
    'text-align:right',
    'float: left;',
    'clear: right;',
    '.thing { left: 0; }',
    'position: absolute; right: 12px;',

    // Inline style attributes, where the property opens the attribute and no
    // brace or semicolon precedes it.
    '<div style="left: 0">',
    "<div style='right: 12px'>",
    '<div style="left:0;top:0">',
    '<div style="position: absolute; right: 4px">',
    '<div style="border-left: 1px solid #ccc">',

    // Tailwind: numeric, fractional, negative, auto, px, arbitrary.
    '<div class="ml-4">',
    '<div class="mr-1.5">',
    '<div class="pl-2 pr-2">',
    '<div class="-ml-2">',
    '<div class="ml-auto">',
    '<div class="mr-px">',
    '<div class="ml-[1rem]">',
    '<div class="left-0">',
    '<div class="-left-2">',
    '<div class="right-auto">',
    '<div class="left-[1rem]">',
    '<div class="text-left">',
    '<div class="text-right font-bold">',
    '<div class="float-right">',
    '<div class="border-l">',
    '<div class="border-r-2">',
    '<div class="border-l-4 border-gray-200">',
    '<div class="border-l-[3px]">',
    '<div class="border-l-red-500">',

    /*
     * The three shapes finding L2 named, each with a sample that fails ONLY its
     * own rule. Mutation testing found the first version wanting: the pattern
     * was added without a sample, so deleting the rule again broke nothing.
     */
    '<div class="rounded-tl-lg">',
    '<div class="rounded-r">',
    'border-top-left-radius: 4px;',
    'border-bottom-right-radius: 2px;',
    // Four- and three-value shorthand name left and right without saying so.
    'margin: 0 4px 0 8px;',
    'padding: 1px 2px 3px;',
    'inset: 0 4px 0 8px;',
]);

it('passes the logical forms that replace them', function (string $sample) {
    // The other half. A detector that flagged everything would also make the
    // scan above pass vacuously — and would fail the moment anyone wrote the
    // correct code.
    expect(firstPhysicalCssProperty($sample))->toBeNull(
        "The logical-CSS detector wrongly flagged: {$sample}",
    );
})->with([
    'margin-inline-start: 1rem;',
    'padding-inline-end: 2px;',
    'border-inline-start: 1px solid red;',
    'text-align: start;',
    'text-align: center;',
    'float: inline-start;',
    'clear: inline-end;',
    'inset-inline-start: 0;',
    'inset-inline-end: 0;',
    'border-inline-start: 1px solid red;',
    'border-inline-end-width: 2px;',
    '<div class="ms-4 me-2">',
    '<div class="ps-2 pe-2">',
    '<div class="-ms-2">',
    '<div class="ms-auto">',
    '<div class="start-0 end-4">',
    '<div class="text-start">',
    '<div class="text-end">',
    '<div class="border-s">',
    '<div class="border-e-2">',
    '<div class="border-s-[3px]">',
    '<div class="border-s-red-500">',

    // The logical counterparts of the three rules added by finding L2.
    '<div class="rounded-ss-lg">',
    '<div class="rounded-e">',
    '<div class="rounded-lg">',
    'border-start-start-radius: 4px;',
    // One and two values are symmetric: nothing to flip.
    'margin: 0;',
    /*
     * Two values that merely CONTAIN spaces. The first version counted
     * whitespace rather than values and flagged both of these, which would have
     * made the rule an obstacle to writing correct CSS.
     */
    'margin: calc(100% - 1rem) auto;',
    'padding: var(--spacing, 1rem 2rem);',
    'margin: calc(1rem + 2px) calc(1rem - 2px);',
    'margin: 0 auto;',
    'padding: 1rem 2rem;',
    'inset: 0;',

    // Inline styles that are already logical.
    '<div style="inset-inline-start: 0">',
    '<div style="margin-inline-end: 4px">',

    // Words that merely contain a forbidden fragment. border-red-500 and
    // border-solid both begin "border-r"/"border-s" and must survive.
    '<div class="html-left-panel">',
    '<div class="overflow-hidden">',
    '<div class="border-red-500">',
    '<div class="border-solid border-2">',
    '<div class="rounded-lg">',
    'grid-template-columns: 1fr;',

    // A JSON/JS key, not a declaration: the name is closed by a quote before
    // the colon, so the inline-style rule must not reach it.
    '{ "left": 0 }',
    "{ 'right': 12 }",
]);

it('uses logical CSS properties only', function () {
    /*
     * BOTH ROOTS, AND JAVASCRIPT TOO (P1-T15, finding L2).
     *
     * This scanned resources/ alone and accepted only .css and .php, so app/ was
     * invisible — and app/ is where every Filament resource lives, the place
     * class strings are most likely to be written next. resources/js/ was
     * excluded by the extension filter for the same reason.
     *
     * Markdown is still not scanned: resources/css/README.md's "Never" column
     * spells out every forbidden property, so scanning documentation would make
     * the rule report itself as a violation.
     */
    $offenders = [];

    $files = array_merge(
        File::allFiles(app_path()),
        File::allFiles(resource_path()),
    );

    foreach ($files as $file) {
        $name = $file->getFilename();
        $path = (string) $file->getRealPath();

        if (in_array($name, STOCK_TAILWIND_PAGES, true)) {
            continue;
        }

        if (! in_array($file->getExtension(), ['css', 'php', 'js'], true)) {
            continue;
        }

        if (($match = firstPhysicalCssProperty((string) file_get_contents($path))) !== null) {
            $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path).' → '.$match;
        }
    }

    expect($offenders)->toBeEmpty(
        "Physical CSS properties do not flip for Arabic. Use the logical form (margin-inline-start, ms-*, text-start, inset-inline-start):\n  "
        .implode("\n  ", $offenders),
    );
});

/*
|--------------------------------------------------------------------------
| The rule that made all of the above possible
|--------------------------------------------------------------------------
*/

/**
 * The first hardcoded user-facing string in this source, or null.
 *
 * A NAMED FUNCTION WITH SAMPLE SETS (P1-T15, finding L3). This was an inline
 * regex with no self-tests, and an inline pattern can only ever be exercised
 * against the codebase as it happens to be today — which is exactly the case
 * where it passes vacuously. The two datasets below are what make deleting a
 * rule fail, and over-broadening one fail differently.
 *
 * The setter list is closed and hand-maintained, which is fail-open by nature:
 * a labelling method Filament adds tomorrow is not on it. That is stated rather
 * than hidden — the list covers every setter this codebase actually calls, and
 * the samples pin the SHAPES it must catch so the pattern cannot quietly narrow.
 */
function firstHardcodedLabel(string $source): ?string
{
    $labelling = '(label|placeholder|helperText|hint|description|heading|modalHeading'
        .'|modalDescription|modalSubmitActionLabel|emptyStateHeading|emptyStateDescription'
        .'|navigationLabel|navigationGroup|tooltip|title|body|badge|suffix|prefix'
        // Added with the detector's own tests: these are called in this codebase
        // and were never covered.
        .'|helperText|trueLabel|falseLabel|displayName|breadcrumb|subheading)';

    /*
     * A literal single- or double-quoted string passed straight to a labelling
     * call. Anything non-empty is an offence — including a lone space, which is
     * how a "harmless" separator becomes untranslatable.
     *
     * \s* after the paren catches the multi-line call style, where the argument
     * sits on the next line.
     */
    if (preg_match("/->{$labelling}\(\s*['\"][^'\"]/", $source, $match) === 1) {
        return trim($match[0]);
    }

    return null;
}

it('detects the hardcoded labels it is meant to detect', function (string $sample) {
    // Deleting a rule from the detector fails here.
    expect(firstHardcodedLabel($sample))->not->toBeNull();
})->with([
    "TextInput::make('name')->label('Name')",
    'TextInput::make("name")->label("Name")',
    "->placeholder('Search')",
    "->helperText('A PDF or an image.')",
    "->navigationLabel('Students')",
    "->modalHeading('Delete user')",
    // A lone space is still a user-facing string.
    "->suffix(' ')",
    // The multi-line call style.
    "->description(\n    'Something explanatory'\n)",
    // Setters added with this detector's own tests.
    "->trueLabel('Active only')",
    "->breadcrumb('Edit')",
]);

it('leaves translated and non-label calls alone', function (string $sample) {
    // Over-broadening the detector fails here. Every one of these is something
    // the codebase legitimately does.
    expect(firstHardcodedLabel($sample))->toBeNull();
})->with([
    "->label(__('staff.name'))",
    '->label(__("staff.name"))',
    "->label(fn (): string => __('staff.name'))",
    // Not a labelling setter at all.
    "->name('email')",
    "->rules('required')",
    '->schema([])',
    // A method whose name merely ENDS in a labelling word.
    "->columnLabel('x')",
    // Empty string: nothing user-facing to translate.
    "->label('')",
]);

/**
 * The first hardcoded user-facing string in a Blade template, or null.
 *
 * SCANNING BLADE FILES IS NOT SCANNING BLADE (P1-T15, review of finding L3).
 * firstHardcodedLabel() recognises PHP calls like ->label('Students'), so
 * adding resources/views/ to its file loop found nothing there: a template does
 * not call setters, it writes markup. `<h1>Students</h1>` sailed straight
 * through, which is the commonest way a Blade file becomes untranslatable.
 *
 * Two surfaces are checked — the text a reader sees between tags, and the
 * attributes that render as text (placeholder, title, alt, aria-label).
 *
 * Everything that is not prose is removed first: Blade expressions and
 * directives, HTML comments, and the contents of <script> and <style>, whose
 * bodies are code and CSS rather than anything a translator would touch.
 */
function firstHardcodedBladeString(string $source): ?string
{
    $stripped = (string) preg_replace(
        [
            '/<script\b[^>]*>.*?<\/script>/is',
            '/<style\b[^>]*>.*?<\/style>/is',
            '/<!--.*?-->/s',
            // {{ ... }}, {!! ... !!}, {{-- ... --}}
            '/\{\{--.*?--\}\}/s',
            '/\{!!.*?!!\}/s',
            '/\{\{.*?\}\}/s',
            // @if (...), @foreach (...), @vite([...]) and bare @csrf
            '/@\w+\s*\([^()]*(\([^()]*\)[^()]*)*\)/s',
            '/@\w+/',
        ],
        ' ',
        $source,
    );

    /*
     * Attributes a reader sees, with a literal value.
     *
     * BOTH QUOTE STYLES, UNICODE LETTERS, AND COMPONENT ATTRIBUTES (review of
     * L3). The first version matched double quotes and ASCII only, so
     * `placeholder='Search students'` passed — and so did `<h1>الطلاب</h1>`.
     * Arabic prose slipping through a check that exists FOR the Arabic phase is
     * the worst blind spot this detector could have had.
     *
     * `label`, `description`, `heading` and `hint` are included because Blade
     * COMPONENTS take them as attributes, which is how a Filament-shaped label
     * reaches a template without ever being a PHP setter call.
     */
    $userFacing = 'placeholder|title|alt|aria-label|label|description|heading|hint';

    foreach (['"', "'"] as $quote) {
        /*
         * (?<![:\w-]) refuses a BOUND attribute. `:label="__('staff.name')"` is
         * PHP that Blade evaluates, not prose — the colon is the whole
         * difference between passing an expression and typing a sentence — and a
         * bound value has no closing brace for the {} exclusion to catch.
         */
        $pattern = '/(?<![:\w-])('.$userFacing.')\s*=\s*'.$quote
            .'([^'.$quote.'{}]*\p{L}{2,}[^'.$quote.'{}]*)'.$quote.'/iu';

        if (preg_match($pattern, $stripped, $match) !== 1) {
            continue;
        }

        // A translation call in an unbound attribute is still translated.
        if (str_contains($match[2], '__(')) {
            continue;
        }

        return trim($match[0]);
    }

    /*
     * Visible text: something between a closing and an opening angle bracket
     * that contains at least two consecutive letters. One letter is too noisy —
     * separators, units and stray punctuation are not sentences.
     */
    if (preg_match('/>\s*([^<>]*\p{L}{2,}[^<>]*)</u', $stripped, $match) === 1) {
        return trim($match[1]);
    }

    return null;
}

it('detects hardcoded text in a Blade template', function (string $sample) {
    // Deleting a rule from the detector fails here.
    expect(firstHardcodedBladeString($sample))->not->toBeNull();
})->with([
    '<h1>Students</h1>',
    '<p>No records found.</p>',
    '<button type="submit">Save changes</button>',
    '<span class="badge">Expired</span>',
    '<input placeholder="Search students">',
    '<img src="/logo.png" alt="Training centre logo">',
    '<a href="/x" title="Open the register">x</a>',
    '<div aria-label="Close dialog"></div>',

    /*
     * The forms the first version missed (review of L3), each an independent
     * sample so deleting any one rule fails on its own.
     */
    "<input placeholder='Search students'>",
    '<h1>الطلاب</h1>',
    '<x-field label="Student name" />',
    "<x-field label='Student name' />",
    '<x-callout description="This cannot be undone" />',
]);

it('leaves translated and non-prose Blade alone', function (string $sample) {
    // Over-broadening fails here: every one of these is something the two
    // templates in this repository legitimately do.
    expect(firstHardcodedBladeString($sample))->toBeNull();
})->with([
    '<h1>{{ __(\'staff.students\') }}</h1>',
    '<x-filament::button type="submit">{{ __(\'auth.update_password\') }}</x-filament::button>',
    '<form wire:submit="save">{{ $this->form }}</form>',
    // Directives and expressions are not prose.
    '<div>@csrf</div>',
    '<div>@if ($x) {{ $y }} @endif</div>',
    // Structure with no text at all.
    '<div class="mt-4"><span></span></div>',
    // A comment is not rendered.
    '<div><!-- a note for the next developer --></div>',
    // Script and style bodies are code.
    '<script>const label = "Students";</script>',
    '<style>.a { content: "Students"; }</style>',
    // Attributes that are not user-facing.
    '<div class="badge" wire:model="name" id="Students"></div>',
    // Translated attributes, in both quote styles.
    '<x-field :label="__(\'staff.name\')" />',
    '<input placeholder="{{ __(\'staff.user\') }}">',
]);

it('has no hardcoded user-facing strings in the application', function () {
    /*
     * Without this test, "everything goes through __()" is a guideline, and the
     * catalogue T14 completed starts rotting from the next resource somebody
     * writes. It held for eleven tasks on discipline alone; it should not have
     * to.
     *
     * VIEWS ARE SCANNED TOO (finding L3). Blade templates render user-facing
     * text as readily as a Filament resource does, and resources/views/ was
     * outside this check entirely.
     */
    $offenders = [];

    $files = array_merge(
        File::allFiles(app_path()),
        File::allFiles(resource_path('views')),
    );

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $path = (string) $file->getRealPath();

        /*
         * Blade gets BOTH detectors and PHP gets one. A template can hold a
         * Filament call (->label('x') inside a @php block or a component) and
         * markup prose, and only the second is what makes scanning views
         * worthwhile — adding the files without a Blade-aware detector found
         * nothing at all.
         */
        if (str_ends_with($path, '.blade.php')) {
            if (in_array($file->getFilename(), STOCK_TAILWIND_PAGES, true)) {
                continue;
            }

            $source = (string) file_get_contents($path);
            $match = firstHardcodedLabel($source) ?? firstHardcodedBladeString($source);
        } else {
            $match = firstHardcodedLabel(appSourceWithoutComments($path));
        }

        if ($match !== null) {
            $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path).' → '.$match;
        }
    }

    expect($offenders)->toBeEmpty(
        "User-facing strings must go through __():\n  ".implode("\n  ", $offenders),
    );
});
