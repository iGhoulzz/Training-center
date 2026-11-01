<?php

declare(strict_types=1);

use App\Filament\Details\AdminDetails;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

/**
 * Architecture guard: every admin detail page is built on the one shared component (P4-T42).
 *
 * A page that extends Filament's `ViewRecord` shows its record through an infolist, and that
 * infolist begins `AdminDetails::schema()`. Without an infolist Filament falls back to the
 * resource's form switched to disabled, which looks like something you could type in; that is
 * what the Students and Courses pages were, beside pages that used an infolist.
 *
 * The scan reads code, not comments: it tokenises each file and takes the body of the
 * `infolist(` method with strings intact, so a word in a docblock never satisfies it.
 */

/**
 * A page whose record is not drawn by an infolist, and why. An allowlisted page may not
 * quietly gain an infolist and stay listed: the test below fails it if it does.
 *
 * @var array<string, string>
 */
const ADMIN_DETAILS_ALLOWLIST = [
    'app/Domain/Staff/Filament/Resources/RoleResource/Pages/ViewRole.php' => 'The permission grid is a form control and is the whole page; it is shown read-only, ticked as the role holds it.',
];

/**
 * The body of the first method with this name in a file's source, comments removed, or null.
 */
function adminDetailsMethodBody(string $source, string $method): ?string
{
    $tokens = token_get_all($source);
    $count = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        if (! is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
            continue;
        }

        $signature = '';
        $j = $i + 1;

        while ($j < $count && $tokens[$j] !== '{' && $tokens[$j] !== ';') {
            $signature .= is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
            $j++;
        }

        if (preg_match('/^\s*'.preg_quote($method, '/').'\s*\(/', $signature) !== 1 || $tokens[$j] !== '{') {
            continue;
        }

        $depth = 0;
        $body = '';

        for (; $j < $count; $j++) {
            $token = $tokens[$j];

            if ($token === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
            } elseif ($token === '}') {
                $depth--;
            }

            if (! is_array($token) || ! in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $body .= is_array($token) ? $token[1] : $token;
            }

            if ($depth === 0) {
                return $body;
            }
        }
    }

    return null;
}

/**
 * What is wrong with one detail page, or an empty list when it is sound.
 *
 * The schema in force is the page's own `infolist()` when it has one, else its resource's.
 *
 * @return array<int, string>
 */
function adminDetailsProblems(string $pageSource, ?string $resourceSource): array
{
    $problems = [];
    $infolist = adminDetailsMethodBody($pageSource, 'infolist')
        ?? ($resourceSource === null ? null : adminDetailsMethodBody($resourceSource, 'infolist'));

    if ($infolist === null) {
        $problems[] = 'has no infolist, so Filament draws the resource form switched to disabled';
    } elseif (! str_contains($infolist, 'AdminDetails::schema(')) {
        $problems[] = 'its infolist does not begin AdminDetails::schema()';
    }

    $content = adminDetailsMethodBody($pageSource, 'content') ?? '';

    if (preg_match("/EmbeddedSchema::make\(\s*'form'\s*\)|getFormContentComponent\(/", $content) === 1) {
        $problems[] = 'its content() embeds the form as a detail view';
    }

    return $problems;
}

/**
 * Every class under app/ that extends ViewRecord, with what is wrong with it.
 *
 * @return array<string, array<int, string>> Repo-relative path => problems.
 */
function adminDetailsAudit(): array
{
    $audit = [];

    foreach (File::allFiles(app_path()) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $relative = str_replace(base_path().DIRECTORY_SEPARATOR, '', (string) $file->getRealPath());
        $class = 'App\\'.str_replace(['/', '.php'], ['\\', ''], substr($relative, strlen('app/')));

        if (! class_exists($class) || ! is_subclass_of($class, ViewRecord::class)) {
            continue;
        }

        $resource = (new ReflectionClass($class))->getStaticPropertyValue('resource', null);
        $resourcePath = is_string($resource) && class_exists($resource) ? (new ReflectionClass($resource))->getFileName() : null;

        $audit[$relative] = adminDetailsProblems(
            (string) file_get_contents((string) $file->getRealPath()),
            $resourcePath === null ? null : (string) file_get_contents($resourcePath),
        );
    }

    ksort($audit);

    return $audit;
}

it('finds the detail pages it is meant to guard', function () {
    $audit = adminDetailsAudit();

    // The floor: fourteen View pages existed when this guard was written, and a scan that finds
    // none of them would pass every test below while guarding nothing. It can only grow.
    expect(count($audit))->toBeGreaterThanOrEqual(14)
        ->and(array_keys($audit))->toContain(
            'app/Domain/Enrollment/Filament/Resources/StudentResource/Pages/ViewStudent.php',
            'app/Domain/Enrollment/Filament/Resources/CourseResource/Pages/ViewCourse.php',
            'app/Domain/Finance/Filament/Resources/PaymentResource/Pages/ViewPayment.php',
            'app/Domain/Finance/Filament/Resources/PayrollRunResource/Pages/ReviewPayrollRun.php',
            'app/Domain/Staff/Filament/Resources/RoleResource/Pages/ViewRole.php',
        );
});

it('builds every detail page on AdminDetails, never on a disabled form', function () {
    $offenders = array_filter(
        adminDetailsAudit(),
        fn (array $problems, string $path): bool => $problems !== [] && ! array_key_exists($path, ADMIN_DETAILS_ALLOWLIST),
        ARRAY_FILTER_USE_BOTH,
    );

    expect($offenders)->toBe([], 'Detail pages draw an infolist from AdminDetails::schema(): '.json_encode($offenders));
});

it('keeps the allowlist to pages that exist, give a reason and still lack an infolist', function () {
    $audit = adminDetailsAudit();

    foreach (ADMIN_DETAILS_ALLOWLIST as $path => $reason) {
        expect(array_key_exists($path, $audit))->toBeTrue("{$path} is allowlisted but is not a View page.")
            ->and($reason)->not->toBe('')
            ->and($audit[$path])->not->toBe([], "{$path} now has a sound infolist, so it needs no allowlist entry.");
    }
});

it('detects a page that skips the shared component', function (string $page, ?string $resource, array $expected) {
    expect(adminDetailsProblems($page, $resource))->toBe($expected);
})->with([
    'a page with its own sound infolist' => [
        '<?php class P { public function infolist($s) { return AdminDetails::schema($s, []); } }',
        null,
        [],
    ],
    'a resource infolist that is sound' => [
        '<?php class P {}',
        '<?php class R { public static function infolist($s) { return AdminDetails::schema($s, []); } }',
        [],
    ],
    'no infolist anywhere' => [
        '<?php class P {}',
        '<?php class R { public static function form($s) { return $s; } }',
        ['has no infolist, so Filament draws the resource form switched to disabled'],
    ],
    'an infolist built by hand' => [
        '<?php class P { public function infolist($s) { return $s->components([Section::make("x")]); } }',
        null,
        ['its infolist does not begin AdminDetails::schema()'],
    ],
    'the shared component named only in a comment' => [
        '<?php class P { public function infolist($s) { /* AdminDetails::schema( */ return $s->components([]); } }',
        null,
        ['its infolist does not begin AdminDetails::schema()'],
    ],
    'a content() that embeds the form' => [
        '<?php class P { public function content($s) { return $s->components([EmbeddedSchema::make(\'form\')]); } }',
        '<?php class R { public static function infolist($s) { return AdminDetails::schema($s, []); } }',
        ['its content() embeds the form as a detail view'],
    ],
]);

it('keeps the shared component\'s class and look in the theme', function () {
    $css = (string) file_get_contents(resource_path('css/filament/admin/theme.css'));

    // The rules are nested under the one prefix, so the label rule sits after the prefix's opening.
    $start = strpos($css, '.'.AdminDetails::CSS_CLASS.' {');

    expect($start)->not->toBeFalse()
        ->and(strpos($css, '& .fi-in-entry-label', (int) $start))->not->toBeFalse();
});
