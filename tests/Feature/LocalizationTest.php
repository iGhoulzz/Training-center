<?php

declare(strict_types=1);

use App\Domain\Enrollment\Enums\BatchStatus;
use App\Domain\Enrollment\Enums\EnrollmentStatus;
use App\Domain\Enrollment\Enums\StudentStatus;
use App\Domain\Staff\Enums\EmploymentType;
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
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $source, $match)) {
            return trim($match[0]);
        }
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
    $offenders = [];

    foreach (File::allFiles(resource_path()) as $file) {
        $name = $file->getFilename();
        $path = (string) $file->getRealPath();

        if (in_array($name, STOCK_TAILWIND_PAGES, true)) {
            continue;
        }

        // css and php only. resources/css/README.md is deliberately not scanned:
        // its "Never" column spells out every forbidden property, so scanning
        // documentation would report the rule as its own violation.
        if (! in_array($file->getExtension(), ['css', 'php'], true)) {
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

it('has no hardcoded user-facing strings in the application', function () {
    // Without this test, "everything goes through __()" is a guideline, and the
    // catalogue this task just completed starts rotting from the next resource
    // somebody writes. It held for eleven tasks on discipline alone; it should
    // not have to.
    $labelling = '(label|placeholder|helperText|hint|description|heading|modalHeading'
        .'|modalDescription|modalSubmitActionLabel|emptyStateHeading|emptyStateDescription'
        .'|navigationLabel|navigationGroup|tooltip|title|body|badge|suffix|prefix)';

    $offenders = [];

    foreach (File::allFiles(app_path()) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $path = (string) $file->getRealPath();

        // A literal single- or double-quoted string passed straight to a
        // labelling call. Anything non-empty is an offence — including a lone
        // space, which is how a "harmless" separator becomes untranslatable.
        if (preg_match("/->{$labelling}\(\s*['\"][^'\"]/", appSourceWithoutComments($path))) {
            $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
        }
    }

    expect($offenders)->toBeEmpty(
        'User-facing strings must go through __(): '.implode(', ', $offenders),
    );
});
