<?php

declare(strict_types=1);

use Tooling\FrontendBuildScope;

/*
|--------------------------------------------------------------------------
| Which changes require the frontend build
|--------------------------------------------------------------------------
|
| The pre-push hook used to run `npm run build` on every push. That was a
| deliberate choice, and the hook explained it: a conditional version had been
| tried, `git diff` finds nothing at pre-push because the worktree is clean by
| then, and a detector that enforces less than it claims is not worth the
| seconds it saves.
|
| The diagnosis was right; the conclusion expired. The build is not seconds — it
| fetches fonts from fonts.bunny.net over the network, and it needs node_modules
| — so it failed twice in one day AFTER a full suite had passed, on branches
| touching no frontend file. The detector now reads git's ref tuples, which is
| what that comment said a correct one would have to do.
|
| THE ASYMMETRY IS THE WHOLE DESIGN. A wrong skip hides a real build failure; a
| wrong build only costs time. Every uncertain case must build. These tests pin
| that direction, not just the happy path.
*/

it('requires a build for every file that feeds Vite', function (string $path) {
    expect(FrontendBuildScope::requiresBuild([$path]))->toBeTrue(
        "[{$path}] can change what npm run build produces, and was not detected."
    );
})->with([
    'resources/css/app.css',
    'resources/js/app.js',
    'resources/js/nested/deeply/component.vue',
    'package.json',
    'package-lock.json',
    'vite.config.js',
    'tailwind.config.js',
    'postcss.config.cjs',
    'public/build/manifest.json',
]);

it('does not require a build for backend-only changes', function (string $path) {
    expect(FrontendBuildScope::requiresBuild([$path]))->toBeFalse(
        "[{$path}] cannot change a built asset, so it must not force a build."
    );
})->with([
    'app/Domain/Enrollment/Actions/IssueStudentCertificateAction.php',
    'tests/Feature/Enrollment/IssueCertificateTest.php',
    'database/migrations/2026_08_27_000100_create_student_certificates_table.php',
    'lang/en/certificates.php',
    'composer.json',
    'composer.lock',
    'docs/ENGINEERING.md',
    '.githooks/pre-push',
    'phpunit.xml',
    'CLAUDE.md',
]);

it('detects a frontend input hidden among many backend files', function () {
    // The realistic shape: one asset touched in a large backend change. A
    // detector that looked only at the first path, or that stopped early, would
    // skip the build here.
    $changed = [
        'app/Domain/Enrollment/Actions/IssueStudentCertificateAction.php',
        'tests/Feature/Enrollment/IssueCertificateTest.php',
        'lang/en/certificates.php',
        'resources/css/app.css',
        'docs/ENGINEERING.md',
    ];

    expect(FrontendBuildScope::requiresBuild($changed))->toBeTrue();
});

it('names the input that forced the build, so a slow push explains itself', function () {
    $changed = [
        'app/Models/User.php',
        'resources/js/app.js',
        'package.json',
        'tests/Feature/ExampleTest.php',
    ];

    expect(FrontendBuildScope::buildInputsIn($changed))
        ->toBe(['resources/js/app.js', 'package.json']);
});

it('returns no build inputs when nothing frontend changed', function () {
    expect(FrontendBuildScope::buildInputsIn(['app/Models/User.php', 'README.md']))->toBe([]);
});

it('treats an empty change set as requiring no build', function () {
    /*
     * "Nothing changed" and "I could not work out what changed" are different
     * questions, and only the first one reaches here. The second is handled in
     * scripts/bin/pre-push-needs-build.php, which exits 0 — build — without
     * consulting this class at all. The test below pins that boundary from the
     * other side.
     */
    expect(FrontendBuildScope::requiresBuild([]))->toBeFalse();
});

it('is not fooled by path separators or a leading ./', function (string $path) {
    // git reports forward slashes, but a caller on Windows or one that prefixed
    // paths from a diff header must not silently produce a skip.
    expect(FrontendBuildScope::requiresBuild([$path]))->toBeTrue(
        "[{$path}] is a frontend input written differently, and must still be detected."
    );
})->with([
    'resources\\css\\app.css',
    './resources/css/app.css',
    '  resources/js/app.js  ',
]);

it('does not match a backend path that merely contains a frontend word', function (string $path) {
    // `resources/` is a PREFIX, not a substring. A file whose name happens to
    // contain it must not force a build, or the detector drifts back towards
    // "always build" without anyone noticing.
    expect(FrontendBuildScope::requiresBuild([$path]))->toBeFalse();
})->with([
    'app/Http/Controllers/ResourcesController.php',
    'app/Domain/Enrollment/Filament/Resources/StudentCertificateResource.php',
    'tests/Feature/Enrollment/CertificateResourceTest.php',
    'docs/package.json.md',
]);
