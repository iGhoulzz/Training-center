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

            preg_match_all("/__\(\s*'([a-z_]+\.[a-zA-Z0-9_.]+)'/", $source, $matches);

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
        if (__($key) === $key) {
            $missing[] = "{$key}  ({$file})";
        }
    }

    expect($missing)->toBeEmpty(
        count($missing).' translation key(s) resolve to themselves, so the panel renders the '
        ."key where it means the label:\n  ".implode("\n  ", $missing),
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

it('uses logical CSS properties only', function () {
    $physical = '/'
        .'margin-(left|right)'
        .'|padding-(left|right)'
        .'|border-(left|right)-'
        .'|text-align:\s*(left|right)'
        .'|float:\s*(left|right)'
        .'|(?<![\w-])(ml|mr|pl|pr)-[0-9]'      // Tailwind physical spacing
        .'|(?<![\w-])(left|right)-[0-9]'       // Tailwind physical insets
        .'|(?<![\w-])text-(left|right)(?![\w-])'
        .'/i';

    $offenders = [];

    foreach (File::allFiles(resource_path()) as $file) {
        $name = $file->getFilename();
        $path = (string) $file->getRealPath();

        if (in_array($name, STOCK_TAILWIND_PAGES, true)) {
            continue;
        }

        if (! in_array($file->getExtension(), ['css', 'php'], true)) {
            continue;
        }

        if (preg_match($physical, (string) file_get_contents($path), $match)) {
            $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path).' → '.$match[0];
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
