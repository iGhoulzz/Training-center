<?php

declare(strict_types=1);

use App\Domain\Publications\Support\ArticleDownloadCounter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

/*
|--------------------------------------------------------------------------
| Only ArticleDownloadCounter writes articles.download_count
|--------------------------------------------------------------------------
|
| docs/ENGINEERING.md, "The one exception: the article download counter",
| permits ONE class to write ONE column outside an actor-first Action, and
| promises the exception is "enforced, not trusted": an architecture test fails
| when any other file writes the column. This is that test.
|
| Without it the exception is only a sentence. The next developer who wants
| "downloads this month" or "reset the count" finds a convenient column and a
| precedent, and adds a second actor-less writer that nothing checks.
|
| WHAT THIS PROVES, AND WHAT IT DOES NOT
| --------------------------------------
| It is a fast early warning over the common write shapes, in the sense
| docs/ENGINEERING.md gives architecture tests: it points at a file, it is not a
| proof that a bypass is impossible. An aliased column name held in a variable, a
| call through a macro, or SQL assembled from fragments reads differently to a
| scan and behaves identically at runtime. The behavioural half lives in
| ArticleDownloadCounterTest: `download_count` is not fillable, so create(),
| update() and fill() ignore it; and the counter issues one conditional UPDATE.
|
| READS ARE NOT WRITES. T12 and T13 sort and display the count, and a scan that
| flagged `orderByDesc('download_count')` would make the library unbuildable. So
| the detector looks for WRITE shapes only — an increment or decrement naming the
| column, an assignment to the property or the array offset, a persisting call
| whose arguments name it, and raw SQL that updates it. Calls are judged by their
| whole argument list, so positional and named arguments are both covered, in any
| order. Each shape has samples below, in both directions, so deleting a rule or
| over-broadening one fails a test of its own.
|
| THE SCAN READS WHAT THE CODE DOES. Comments are stripped by the tokenizer first,
| because this codebase's docblocks quote the very patterns being searched for —
| the counter's own docblock quotes the SQL it runs.
|
| No database is touched. RefreshDatabase is declared only because
| DatabaseIsolationTest requires every feature test to declare an isolation trait
| or be named on its reviewed read-only list, and that list is not this task's to
| edit; the declaration costs nothing here.
*/

uses(RefreshDatabase::class);

/** The one application file allowed to write the column. */
const ARTICLE_COUNTER_WRITER = 'app/Domain/Publications/Support/ArticleDownloadCounter.php';

/**
 * Persisting calls: any of them with the column named in its arguments is a write.
 *
 * `make` and `replace` are deliberately ABSENT. Filament's `TextColumn::make('download_count')`
 * and `Str::replace(...)` take the name as an ordinary argument, and T12 and T13
 * will display and sort on the column; Eloquent's own `make()` builds a model
 * without saving it, through fill(), which ignores a non-fillable column anyway.
 */
const ARTICLE_PERSISTING_CALLS = 'update|updateQuietly|updateOrInsert|updateOrCreate|upsert|insert|insertOrIgnore'
    .'|insertGetId|insertUsing|create|createQuietly|forceCreate|forceCreateQuietly|firstOrCreate|createOrFirst'
    .'|forceFill|fill|setAttribute|setRawAttributes'
    // The increment family. Judged by the call's whole argument list rather than
    // by the first argument, so `increment(column: 'download_count')` and
    // `increment(amount: 2, column: 'download_count')` are caught as surely as
    // `increment('download_count')`.
    .'|increment|decrement|incrementEach|decrementEach|incrementQuietly|decrementQuietly';

/** PHP source with comments and docblocks removed. Strings are KEPT: the column name is one. */
function articleCodeWithoutComments(string $source): string
{
    $code = '';

    foreach (token_get_all($source) as $token) {
        if (is_array($token)) {
            $code .= in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $token[1];

            continue;
        }

        $code .= $token;
    }

    return $code;
}

/**
 * The text between a call's parentheses, from the character after the opening one.
 *
 * String-aware, so a parenthesis inside a literal does not unbalance the count.
 */
function articleBalancedArguments(string $code, int $afterOpeningParenthesis): string
{
    $depth = 1;
    $length = strlen($code);

    for ($i = $afterOpeningParenthesis; $i < $length; $i++) {
        $character = $code[$i];

        if ($character === "'" || $character === '"') {
            for ($i++; $i < $length && $code[$i] !== $character; $i++) {
                if ($code[$i] === '\\') {
                    $i++;
                }
            }

            continue;
        }

        if ($character === '(') {
            $depth++;
        } elseif ($character === ')' && --$depth === 0) {
            return substr($code, $afterOpeningParenthesis, $i - $afterOpeningParenthesis);
        }
    }

    return substr($code, $afterOpeningParenthesis);
}

/**
 * Every shape in $source that writes the download_count column, as matched text.
 *
 * @return list<string>
 */
function articleDownloadCountWriteShapes(string $source): array
{
    $code = articleCodeWithoutComments($source);
    $found = [];

    // 1. An assignment to the property: $a->download_count = 0, += 1, ++.
    //    `==`, `===`, `=>`, `>=` and `<=` are comparisons or array syntax, not writes.
    preg_match_all(
        '/(?:->|\?->)\s*download_count\s*(?:=(?![=>])|\+=|-=|\*=|\/=|\.=|\?\?=|\+\+|--)|(?:\+\+|--)\s*\$[\w$]+\s*(?:->|\?->)\s*download_count/',
        $code,
        $matches,
    );
    array_push($found, ...$matches[0]);

    // 2. An assignment to the array offset: $attributes['download_count'] = 0.
    preg_match_all(
        '/\[\s*[\'"]download_count[\'"]\s*\]\s*(?:=(?![=>])|\+=|-=|\.=|\?\?=)/',
        $code,
        $matches,
    );
    array_push($found, ...$matches[0]);

    // 3. A persisting or incrementing call whose arguments name the column, wherever
    //    in them and however they are passed: ->update(['download_count' => ...]),
    //    ->increment('download_count'), ->increment(column: 'download_count'),
    //    spread over any number of lines.
    preg_match_all(
        '/(?:->|::)\s*(?:'.ARTICLE_PERSISTING_CALLS.')\s*\(/',
        $code,
        $calls,
        PREG_OFFSET_CAPTURE,
    );

    foreach ($calls[0] as [$call, $offset]) {
        $arguments = articleBalancedArguments($code, $offset + strlen($call));

        if (str_contains($arguments, 'download_count')) {
            $found[] = trim($call).'…download_count…)';
        }
    }

    // 4. Raw SQL that updates, inserts or replaces the column: the verb comes
    //    before the column in every statement that writes it, including
    //    `ON DUPLICATE KEY UPDATE download_count = ...`. A READ of it
    //    (`select download_count`, `order by download_count desc`) has no verb.
    preg_match_all(
        '/[\'"][^\'"]*\b(?:update|insert|replace)\b[^\'"]*\bdownload_count\b[^\'"]*[\'"]/i',
        $code,
        $matches,
    );
    array_push($found, ...$matches[0]);

    return array_map(fn (string $shape): string => (string) preg_replace('/\s+/', ' ', $shape), $found);
}

it('recognises every write shape it claims to', function (string $sample) {
    expect(articleDownloadCountWriteShapes("<?php\n{$sample}"))->not->toBeEmpty();
})->with([
    'increment' => ["\$article->increment('download_count');"],
    'increment by an amount' => ["\$article->increment('download_count', 5);"],
    'static query increment' => ["Article::query()->whereKey(1)->increment('download_count');"],
    'increment, double quotes' => ['$article->increment("download_count");'],
    'increment, spaced' => ["\$a -> increment ( 'download_count' );"],
    'incrementEach' => ["Article::query()->incrementEach(['download_count' => 1]);"],
    'increment, named argument' => ["Article::query()->whereKey(1)->increment(column: 'download_count');"],
    'increment, named arguments out of order' => ["\$article->increment(amount: 2, column: 'download_count');"],
    'decrement, named argument' => ['$article->decrement(column: "download_count");'],
    'incrementEach, named argument' => ["Article::query()->incrementEach(columns: ['download_count' => 1]);"],
    'update, named argument' => ["\$article->update(attributes: ['download_count' => 0]);"],
    'setAttribute, named argument' => ["\$article->setAttribute(key: 'download_count', value: 4);"],
    'quiet increment' => ["\$article->incrementQuietly('download_count');"],
    'decrement' => ['$a->decrement("download_count", 3);'],
    'property assignment' => ['$article->download_count = 0;'],
    'property assignment, spaced' => ['$article -> download_count=0;'],
    'null-safe property assignment' => ['$article?->download_count = 0;'],
    'property compound assignment' => ['$article->download_count += 1;'],
    'property postfix increment' => ['$article->download_count++;'],
    'property prefix increment' => ['++$article->download_count;'],
    'array offset assignment' => ["\$attributes['download_count'] = 0;"],
    'update with the column' => ["\$article->update(['download_count' => 0]);"],
    'multi-line query update' => ["Article::query()\n    ->where('id', 1)\n    ->update([\n        'title_en' => 'x',\n        'download_count' => DB::raw('download_count + 1'),\n    ]);"],
    'forceFill' => ["\$article->forceFill(['download_count' => 5])->save();"],
    'static create' => ["Article::create(['download_count' => 1]);"],
    'table insert' => ["DB::table('articles')->insert(['download_count' => 1]);"],
    'upsert' => ["DB::table('articles')->upsert(\$rows, ['id'], ['download_count']);"],
    'setAttribute' => ["\$article->setAttribute('download_count', 4);"],
    'a nested call inside the arguments' => ["\$article->update(['x' => trim(\$y), 'download_count' => count([1, 2])]);"],
    'raw sql update' => ["DB::update('update articles set download_count = 0');"],
    'raw sql, upper case' => ['DB::statement("UPDATE articles SET download_count = download_count + 1");'],
    'raw sql, insert' => ["DB::statement('insert into articles (download_count) values (9)');"],
    'raw sql, upsert' => ["DB::statement('insert into articles (id) values (1) on duplicate key update download_count = 0');"],
]);

it('does not mistake reads, sorts, display or prose for a write', function (string $sample) {
    expect(articleDownloadCountWriteShapes("<?php\n{$sample}"))->toBe([]);
})->with([
    'a property read' => ['$count = $article->download_count;'],
    'a comparison' => ['if ($article->download_count >= 100) { return; }'],
    'a strict comparison' => ['if ($article->download_count === 5) { return; }'],
    'a loose comparison' => ['if ($article->download_count == 5) { return; }'],
    'a less-or-equal comparison' => ['if ($article->download_count <= 5) { return; }'],
    'a sort' => ["Article::query()->orderByDesc('download_count')->get();"],
    'a sort map' => ["\$sorts = ['downloads' => 'download_count', 'download_count' => 'desc'];"],
    'a raw sort' => ["Article::query()->orderByRaw('download_count desc')->get();"],
    'a cast' => ["return ['download_count' => 'integer', 'issued_on' => 'date'];"],
    'a table column' => ["TextColumn::make('download_count')->sortable();"],
    'a string replace' => ["Str::replace('download_count', 'downloads', \$label);"],
    'a select' => ["Article::query()->select(['id', 'download_count'])->get();"],
    'a raw read' => ["DB::select('select download_count from articles where id = ?', [1]);"],
    'a view value' => ["return view('x', ['download_count' => \$article->download_count]);"],
    'a docblock' => ["/** \$article->download_count = 0 is forbidden; use increment('download_count'). */\n\$x = 1;"],
    'a line comment' => ["// \$article->increment('download_count');\n\$x = 1;"],
    'another column' => ["\$article->increment('attempts');"],
    'another column, named argument' => ["\$article->increment(column: 'attempts', amount: 2);"],
    'an update that does not name it' => ["\$article->update(['title_en' => 'x']);"],
    'an update elsewhere in the file' => ["\$a->update(['title_en' => 'x']);\n\$b = \$c->download_count;"],
    'a where on it' => ["Article::query()->where('download_count', '>', 5)->update(['topic' => 'x']);"],
]);

it('finds no second writer of download_count under app/ or routes/', function () {
    $offenders = [];
    $scanned = 0;
    $base = str_replace('\\', '/', base_path()).'/';

    foreach ([app_path(), base_path('routes')] as $root) {
        foreach (File::allFiles($root) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $scanned++;
            $relative = str_replace($base, '', str_replace('\\', '/', (string) $file->getRealPath()));

            if ($relative === ARTICLE_COUNTER_WRITER) {
                continue;
            }

            foreach (articleDownloadCountWriteShapes($file->getContents()) as $shape) {
                $offenders[] = "{$relative}: {$shape}";
            }
        }
    }

    // A scan that matches nothing passes every assertion built on it.
    expect($scanned)->toBeGreaterThan(100)
        ->and($offenders)->toBe(
            [],
            'Only ArticleDownloadCounter may write articles.download_count. docs/ENGINEERING.md, "The one exception", '
            ."says a second actor-less writer needs its own owner decision. These files write it:\n".implode("\n", $offenders),
        );
});

it('keeps the allowlisted writer real, so the exemption cannot outlive the file', function () {
    $path = base_path(ARTICLE_COUNTER_WRITER);

    // If the counter moved, this exemption would silently cover nothing, and a
    // replacement writer elsewhere would be reported — or, renamed into this path,
    // exempted. Pin all three: it exists, it is the class it names, and it still
    // does the one thing it is allowed to do.
    expect(File::exists($path))->toBeTrue()
        ->and((new ReflectionClass(ArticleDownloadCounter::class))->getFileName())->toBe(realpath($path))
        ->and(articleDownloadCountWriteShapes(File::get($path)))->not->toBeEmpty();
});

it('does not dress the counter up as an Action', function () {
    // The exception is named as one so it cannot be cited as precedent: the class
    // is not an Action and does not live where Actions live.
    expect(ArticleDownloadCounter::class)->not->toEndWith('Action')
        ->and(ArticleDownloadCounter::class)->not->toContain('\\Actions\\');
});
