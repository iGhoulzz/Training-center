<?php

declare(strict_types=1);

namespace Tooling;

/**
 * The single definition of which files can change what `npm run build` produces.
 *
 * The pre-push hook consults this to decide whether the frontend build has to
 * run for the commits being pushed. CI does not consult it: CI builds on every
 * push, unconditionally, and that is what makes a local skip safe.
 *
 * WHY THIS IS A CLASS AND NOT A grep IN THE HOOK. A pattern living inside a
 * shell script can only be exercised by running the whole gate, so a mistake in
 * it would be found by a push that wrongly skipped — which is exactly the
 * failure nobody notices. Here it has a test.
 */
final class FrontendBuildScope
{
    /**
     * Path prefixes and exact filenames that feed the Vite build.
     *
     * BLADE IS DELIBERATELY ABSENT. `resources/views` is not an input to the
     * build — Blade is rendered at request time, and changing a template cannot
     * change a built asset. `resources/` is listed as a prefix anyway, so views
     * are covered incidentally; that is over-inclusive in the safe direction and
     * costs a build nobody needed, never a skip somebody did.
     *
     * @var list<string>
     */
    private const PREFIXES = [
        'resources/',
        'public/build/',
    ];

    /**
     * @var list<string>
     */
    private const FILES = [
        'package.json',
        'package-lock.json',
        'vite.config.js',
        'vite.config.ts',
        'vite.config.mjs',
        'tailwind.config.js',
        'tailwind.config.ts',
        'postcss.config.js',
        'postcss.config.cjs',
        'postcss.config.mjs',
    ];

    /**
     * Does this set of changed paths require the frontend build to run?
     *
     * An EMPTY list returns false: no files changed means nothing that could
     * affect the build changed. The caller is responsible for distinguishing
     * "nothing changed" from "I could not work out what changed" — the second
     * must build, and this method is never asked about it.
     *
     * @param  list<string>  $changedPaths  repository-relative, forward slashes
     */
    public static function requiresBuild(array $changedPaths): bool
    {
        foreach ($changedPaths as $path) {
            if (self::isBuildInput($path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every path from the set that triggered the decision.
     *
     * Used by the hook to say WHICH file forced a build, so a developer looking
     * at a slow push can see the reason rather than guessing at it.
     *
     * @param  list<string>  $changedPaths
     * @return list<string>
     */
    public static function buildInputsIn(array $changedPaths): array
    {
        return array_values(array_filter($changedPaths, self::isBuildInput(...)));
    }

    private static function isBuildInput(string $path): bool
    {
        $path = ltrim(str_replace('\\', '/', trim($path)), './');

        if ($path === '') {
            return false;
        }

        if (in_array($path, self::FILES, true)) {
            return true;
        }

        foreach (self::PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
