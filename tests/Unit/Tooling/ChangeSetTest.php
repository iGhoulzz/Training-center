<?php

declare(strict_types=1);

use Tooling\ChangeSet;

/*
 * Classification is what decides whether a turn is "documentation-only" and
 * therefore skips the gate. Get it wrong in the permissive direction and real
 * code ships unchecked, so every shape git can emit is pinned here.
 *
 * The fixtures are raw `--porcelain=v1 -z` payloads: NUL-terminated, unquoted.
 */

it('separates staged from unstaged tracked changes', function () {
    $changes = ChangeSet::parse("M  app/Staged.php\0 M app/Unstaged.php\0");

    expect($changes->staged)->toBe(['app/Staged.php'])
        ->and($changes->unstagedTracked)->toBe(['app/Unstaged.php'])
        ->and($changes->untracked)->toBe([]);
});

it('records a file that is both staged and modified again in both sets', function () {
    $changes = ChangeSet::parse("MM app/Both.php\0");

    expect($changes->staged)->toBe(['app/Both.php'])
        ->and($changes->unstagedTracked)->toBe(['app/Both.php'])
        // all() de-duplicates, or the gate would check the same file twice
        ->and($changes->all())->toBe(['app/Both.php']);
});

it('collects untracked files', function () {
    $changes = ChangeSet::parse("?? scripts/New.php\0");

    expect($changes->untracked)->toBe(['scripts/New.php'])
        ->and($changes->staged)->toBe([])
        ->and($changes->unstagedTracked)->toBe([]);
});

/*
 * A rename carries its ORIGIN as a second NUL-terminated field. If that field is
 * not consumed it is read as the next status entry, and `substr($entry, 3)`
 * chops three characters off a real path — so `app/Origin.php` would silently
 * become `Origin.php` and be classified as something else entirely.
 */
it('consumes the origin path of a rename instead of reading it as a new entry', function () {
    $changes = ChangeSet::parse("R  app/New.php\0app/Origin.php\0?? notes.md\0");

    expect($changes->staged)->toBe(['app/New.php'])
        ->and($changes->untracked)->toBe(['notes.md'])
        // The origin path must not appear anywhere as its own change.
        ->and($changes->all())->not->toContain('app/Origin.php')
        ->and($changes->all())->not->toContain('/Origin.php');
});

it('handles copies the same way as renames', function () {
    $changes = ChangeSet::parse("C  app/Copy.php\0app/Source.php\0");

    expect($changes->staged)->toBe(['app/Copy.php'])
        ->and($changes->all())->toBe(['app/Copy.php']);
});

/*
 * Without -z git QUOTES these paths, and a classifier matching on `.php` then
 * misses them. These fixtures are what -z actually produces: literal bytes.
 */
it('reads filenames containing spaces, quotes and non-ASCII bytes', function () {
    $changes = ChangeSet::parse("?? app/a file with spaces.php\0?? app/has\"quote.php\0?? lang/ar/الصفحة.php\0");

    expect($changes->untracked)->toBe([
        'app/a file with spaces.php',
        'app/has"quote.php',
        'lang/ar/الصفحة.php',
    ]);
});

it('treats an empty payload as no changes', function () {
    expect(ChangeSet::parse('')->isEmpty())->toBeTrue()
        ->and(ChangeSet::parse("\0")->isEmpty())->toBeTrue();
});

describe('documentation-only detection', function () {
    it('accepts markdown and anything under docs/', function () {
        $changes = ChangeSet::parse("M  README.md\0 M docs/ENGINEERING.md\0?? docs/reviews/new.md\0");

        expect($changes->isDocumentationOnly())->toBeTrue();
    });

    /*
     * The three cases that matter. A turn is not documentation because the ONE
     * set someone happened to look at is — each of these would have slipped
     * through a gate built on `git diff --check` alone.
     */
    it('is false when code hides in the staged set', function () {
        expect(ChangeSet::parse("M  app/Real.php\0 M docs/a.md\0")->isDocumentationOnly())->toBeFalse();
    });

    it('is false when code hides in the unstaged set', function () {
        expect(ChangeSet::parse("M  docs/a.md\0 M app/Real.php\0")->isDocumentationOnly())->toBeFalse();
    });

    it('is false when code hides in the untracked set', function () {
        expect(ChangeSet::parse("M  docs/a.md\0?? app/Real.php\0")->isDocumentationOnly())->toBeFalse();
    });

    it('does not mistake a file merely containing "docs" for documentation', function () {
        expect(ChangeSet::isDocumentation('app/Docs/Controller.php'))->toBeFalse()
            ->and(ChangeSet::isDocumentation('app/docs.php'))->toBeFalse()
            ->and(ChangeSet::isDocumentation('docs/a.md'))->toBeTrue();
    });

    it('matches the markdown extension case-insensitively', function () {
        expect(ChangeSet::isDocumentation('README.MD'))->toBeTrue();
    });
});
