<?php

declare(strict_types=1);

use App\Filament\Pages\PasswordChange;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Every portal page resolves the viewer through AuthenticatedStudent
|--------------------------------------------------------------------------
|
| PARTIAL DEFENCE IN DEPTH — STATED PLAINLY, NOT IMPLIED.
| -----------------------------------------------------------
| This is a SOURCE SCAN. It proves a page's source names
| AuthenticatedStudent::class somewhere in its own file. It does NOT prove the
| query built from that call was constrained to the resolved student — a page
| could call resolve(), throw the result away, and query unscoped, and this
| test would still pass. That correctness claim belongs to
| PortalRowIsolationTest, which signs in as one student, builds distinctive
| data for another, and asserts none of it leaks into the first student's
| rendered pages. Design section 4.1 names both mechanisms and is explicit that
| only the second is sufficient; this file is the first and does not pretend
| otherwise.
|
| THE PAGE LIST COMES FROM THE PANEL, NOT FROM A LIST OF DIRECTORIES.
| ---------------------------------------------------------------------
| CROSS-REVIEW FINDING, AND THE EARLIER VERSION'S OWN DOCBLOCK WAS THE DEFECT.
| It scanned two hardcoded directories and claimed they were "every file
| Filament will actually route a request to as a portal page". That was false.
| StudentPanelProvider also registers pages by class name through ->pages([...])
| — PasswordChange arrives that way today — and such a page is fully routable
| while being invisible to a directory scan.
|
| Review proved it rather than arguing it: a real Page subclass with no
| AuthenticatedStudent anywhere, querying every enrolment in the system, added
| to the existing ->pages([...]) array, was routed at portal/zz-leaky-probe and
| THIS TEST STAYED GREEN. The `grep -c "discoverPages" == 2` control the plan
| relies on did not move either, because nothing about discoverPages() changed.
|
| So the list is now whatever the panel says it routes. A page added by any
| registration mechanism — discoverPages(), ->pages([]), or one invented later —
| is in scope automatically, which is the property the plan actually wanted.
|
| PasswordChange is excluded BY NAME and for a reason: it answers "who is signed
| in", not "which student's data is this", so it has nothing to resolve. Naming
| it here means adding a second exemption is a visible edit rather than a
| silently widened net.
*/

/**
 * Every page the student panel actually routes, minus the deliberate exemption.
 *
 * @return array<int, string> absolute file paths
 */
function portalPageFiles(): array
{
    $exempt = [PasswordChange::class];

    $files = [];

    foreach (Filament::getPanel('student')->getPages() as $page) {
        if (! is_string($page) || in_array($page, $exempt, true)) {
            continue;
        }

        $file = (new ReflectionClass($page))->getFileName();

        if (is_string($file)) {
            $files[] = $file;
        }
    }

    sort($files);

    return array_values(array_unique($files));
}

it('scans every page the student panel routes, except the named exemption', function () {
    /*
     * A scan that silently matches nothing passes every assertion built on it.
     *
     * This is an EQUALITY check, not a floor. Review pointed out that the old
     * `>= 3` could not notice a directory that stopped being scanned as long as
     * another still held three files. Deriving from the panel makes an exact
     * count meaningful: the panel routes exactly the pages below plus the one
     * exemption, and any change to that set should be a deliberate edit here.
     */
    $routed = array_values(array_filter(
        Filament::getPanel('student')->getPages(),
        is_string(...),
    ));

    expect(portalPageFiles())->toHaveCount(
        count($routed) - 1,
        'The panel routes '.count($routed).' page(s) and exactly one (PasswordChange) is exempt. '
        .'If a second exemption is genuinely warranted, add it to $exempt with a reason rather than '
        .'letting the count drift.',
    );

    expect(portalPageFiles())->not->toBeEmpty();
});

it('includes a page registered by class name, not only ones found by discoverPages', function () {
    /*
     * THE REGRESSION TEST FOR THE HOLE ITSELF.
     *
     * PasswordChange is registered through ->pages([...]), so it is the proof
     * that this scan sees that mechanism at all. It is the one page exempted
     * from the resolver rule — but it must still be VISIBLE to the enumeration,
     * or the exemption is indistinguishable from the blindness review found.
     */
    $routed = Filament::getPanel('student')->getPages();

    expect(in_array(PasswordChange::class, $routed, true))
        ->toBeTrue('The panel no longer routes PasswordChange by class name, so this file no longer '
            .'proves the ->pages([...]) registration mechanism is covered by the scan.');
});

it('resolves the viewing student through AuthenticatedStudent on every portal page', function () {
    $offenders = [];

    foreach (portalPageFiles() as $path) {
        if (preg_match('/\bAuthenticatedStudent::class\b/', appSourceWithoutComments($path)) !== 1) {
            $offenders[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path);
        }
    }

    expect($offenders)->toBeEmpty(
        'Every portal page must resolve the viewing student through AuthenticatedStudent, '
        ."never derive one for itself. Offending file(s):\n  ".implode("\n  ", $offenders),
    );
});
