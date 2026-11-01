<?php

declare(strict_types=1);

use App\Domain\Staff\Filament\Resources\ActivityResource\Pages\ListActivities;
use App\Filament\Tables\AdminTable;
use Filament\Tables\Table;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Architecture guard: every admin list is built on the one shared table (P4-T41).
 *
 * A class under app/ that defines `table(Table $table)` must pass its table through
 * `AdminTable::configure()`, and must not use Filament's flexible row layout
 * (`Split`, `Stack`, `Grid`, `contentGrid`): that layout draws no header row and
 * sizes each row to its own content, so the columns drift from row to row.
 *
 * The scan reads the code, not the comments: it tokenises each file, finds each
 * `table(Table $table)` method and takes its body with strings intact, so a word in
 * a docblock never trips it and a brace inside a string never unbalances it.
 */

/**
 * A class whose table() only adjusts the table its resource has already configured.
 * The reason is the entry's value. An allowlisted file may not define columns of its
 * own: the test below fails it if it does, so the list cannot hide a real table.
 *
 * @var array<string, string>
 */
const ADMIN_TABLE_ALLOWLIST = [
    'app/Domain/Enrollment/Filament/Resources/StudentResource/Pages/ListStudents.php' => 'The page only sets the row action on the table StudentResource::table() has already passed through AdminTable.',
];

/**
 * Every `table(Table $table)` method in one file's source, as its body without comments.
 *
 * @return array<int, string>
 */
function adminTableBodies(string $source): array
{
    $tokens = token_get_all($source);
    $count = count($tokens);
    $bodies = [];

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

        if (preg_match('/^\s*table\s*\(\s*Table\s+\$table\b/', $signature) !== 1 || $tokens[$j] !== '{') {
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
                break;
            }
        }

        $bodies[] = $body;
    }

    return $bodies;
}

/**
 * What is wrong with one table() body, or an empty list when it is sound.
 *
 * @return array<int, string>
 */
function adminTableProblems(string $body, bool $allowlisted): array
{
    $problems = [];

    if (! $allowlisted && ! str_contains($body, 'AdminTable::configure(')) {
        $problems[] = 'does not pass its table through AdminTable::configure()';
    }

    if (preg_match('/\b(?:Split|Stack|Grid)::make\b|->contentGrid\(/', $body) === 1) {
        $problems[] = 'uses the flexible row layout (Split, Stack, Grid or contentGrid)';
    }

    if ($allowlisted && str_contains($body, '->columns(')) {
        $problems[] = 'is allowlisted but defines columns of its own';
    }

    return $problems;
}

/**
 * Every class under app/ that defines a table, with what is wrong with each definition.
 *
 * @return array<string, array<int, string>> Repo-relative path => problems, for every definition found.
 */
function adminTableAudit(): array
{
    $audit = [];

    foreach (File::allFiles(app_path()) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $relative = str_replace(base_path().DIRECTORY_SEPARATOR, '', (string) $file->getRealPath());

        foreach (adminTableBodies((string) file_get_contents((string) $file->getRealPath())) as $body) {
            $audit[$relative] = [
                ...($audit[$relative] ?? []),
                ...adminTableProblems($body, array_key_exists($relative, ADMIN_TABLE_ALLOWLIST)),
            ];
        }
    }

    ksort($audit);

    return $audit;
}

it('finds the admin tables it is meant to guard', function () {
    $audit = adminTableAudit();

    // The floor: 22 classes defined a table when this guard was written, and a scan that
    // finds none of them would pass every test below while guarding nothing. It can only grow.
    expect(count($audit))->toBeGreaterThanOrEqual(22)
        ->and(array_keys($audit))->toContain(
            'app/Domain/Finance/Filament/Resources/ChargeResource.php',
            'app/Domain/Staff/Filament/Resources/ActivityResource.php',
            'app/Domain/Enrollment/Filament/Resources/StudentResource.php',
            'app/Domain/Enrollment/Filament/Resources/BatchResource/RelationManagers/InstructorsRelationManager.php',
            'app/Domain/Finance/Filament/Pages/ExportHistory.php',
        );
});

it('puts every admin table on AdminTable, with no flexible row layout', function () {
    $offenders = array_filter(adminTableAudit(), fn (array $problems): bool => $problems !== []);

    expect($offenders)->toBe([], 'Admin lists use AdminTable::configure() and real columns: '.json_encode($offenders));
});

it('keeps the allowlist to files that exist and give a reason', function () {
    foreach (ADMIN_TABLE_ALLOWLIST as $path => $reason) {
        expect(is_file(base_path($path)))->toBeTrue("{$path} is allowlisted but does not exist.")
            ->and($reason)->not->toBe('')
            ->and(adminTableBodies((string) file_get_contents(base_path($path))))->not->toBe([], "{$path} no longer defines a table, so it needs no allowlist entry.");
    }
});

it('imports Filament\'s row layout nowhere in the application', function () {
    $importers = collect(File::allFiles(app_path()))
        ->filter(fn ($file): bool => $file->getExtension() === 'php')
        ->filter(fn ($file): bool => str_contains(appSourceWithoutComments((string) $file->getRealPath()), 'Filament\Tables\Columns\Layout\\'))
        ->map(fn ($file): string => str_replace(base_path().DIRECTORY_SEPARATOR, '', (string) $file->getRealPath()))
        ->values()
        ->all();

    expect($importers)->toBe([]);
});

it('detects a table that does not use the shared component or that uses the row layout', function (string $source, bool $allowlisted, array $expected) {
    $bodies = adminTableBodies($source);

    expect($bodies)->toHaveCount(1)
        ->and(adminTableProblems($bodies[0], $allowlisted))->toBe($expected);
})->with([
    'a sound table' => [
        '<?php class A { public static function table(Table $table): Table { return AdminTable::configure($table)->columns([TextColumn::make("a")]); } }',
        false,
        [],
    ],
    'a table that skips the component' => [
        '<?php class A { public static function table(Table $table): Table { return $table->columns([TextColumn::make("a")]); } }',
        false,
        ['does not pass its table through AdminTable::configure()'],
    ],
    'a table that re-introduces Split' => [
        '<?php class A { public static function table(Table $table): Table { return AdminTable::configure($table)->columns([Split::make([TextColumn::make("a")])]); } }',
        false,
        ['uses the flexible row layout (Split, Stack, Grid or contentGrid)'],
    ],
    'a table that re-introduces Stack' => [
        '<?php class A { public function table(Table $table): Table { return AdminTable::configure($table)->columns([Stack::make([])]); } }',
        false,
        ['uses the flexible row layout (Split, Stack, Grid or contentGrid)'],
    ],
    'a table that re-introduces contentGrid' => [
        '<?php class A { public static function table(Table $table): Table { return AdminTable::configure($table)->contentGrid(["md" => 2]); } }',
        false,
        ['uses the flexible row layout (Split, Stack, Grid or contentGrid)'],
    ],
    'an allowlisted table that defines columns' => [
        '<?php class A { public function table(Table $table): Table { return $table->columns([]); } }',
        true,
        ['is allowlisted but defines columns of its own'],
    ],
    'a brace inside a string and a word in a comment' => [
        '<?php class A { /** Split::make */ public static function table(Table $table): Table { // Stack::make
            return AdminTable::configure($table)->emptyStateHeading("{1} none"); } }',
        false,
        [],
    ],
]);

it('reads a table method whatever its visibility and keeps the next method out of its body', function () {
    $source = '<?php class A {
        public static function table(Table $table): Table { return AdminTable::configure($table); }
        public static function other(): array { return [Split::make([])]; }
    }';

    $bodies = adminTableBodies($source);

    expect($bodies)->toHaveCount(1)->and($bodies[0])->not->toContain('Split');
});

it('draws the shared table\'s header, rows and empty state in the theme, under its own class', function () {
    $css = (string) file_get_contents(resource_path('css/filament/admin/theme.css'));
    $root = '.'.AdminTable::CSS_CLASS;

    // The header row: small, capitalised, muted, so it reads as headings and not as a data row.
    expect(preg_match('/'.preg_quote($root, '/').' \.fi-ta-header-cell \{[^}]*uppercase[^}]*text-gray-400|'.preg_quote($root, '/').' \.fi-ta-header-cell \{[^}]*text-gray-400[^}]*uppercase/s', $css))->toBe(1, 'The header cell rule is gone from theme.css.')
        ->and($css)->toContain($root.' .fi-ta-table')
        ->and($css)->toContain($root.' .fi-ta-row')
        ->and($css)->toContain('hover:bg-fill-hover')
        ->and($css)->toContain($root.' .fi-ta-empty-state');
});

it('puts the shared table\'s class and its row class on every list it configures', function () {
    $table = AdminTable::configure(new Table(Livewire::new(ListActivities::class)));

    expect($table->getExtraAttributes())->toBe(['class' => AdminTable::CSS_CLASS])
        ->and($table->getRecordClasses([]))->toBe([AdminTable::ROW_CLASS])
        ->and($table->isStriped())->toBeFalse()
        ->and($table->getPaginationPageOptions())->toBe(AdminTable::PAGE_OPTIONS)
        ->and($table->getDefaultPaginationPageOption())->toBe(AdminTable::DEFAULT_PAGE_SIZE);
});
