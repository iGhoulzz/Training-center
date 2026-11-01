<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The student portal's theme and fonts (P4-T42)
|--------------------------------------------------------------------------
|
| Source checks, in the style of ThemeShellTest and DesignSystemTest: a
| stylesheet is not rendered by the test suite (CI runs it before the assets are
| built), so what can be asserted is that the rule exists and says what it must.
| Each rule is found by its exact selector, so a selector that merely resembles
| it does not satisfy the test.
|
| THE HOVER RULES ARE WHY THIS FILE EXISTS. Filament paints a linked sidebar item
| gray-100 on hover and on keyboard focus with
| `.fi-sidebar-item.fi-sidebar-item-has-url > .fi-sidebar-item-btn`, which a rule
| on the item button alone cannot out-specify. On the portal's navy sidebar that
| was cream text on a near-white box.
*/

/** The portal theme with its comments removed, so a selector named in a comment is not a rule. */
function portalThemeCss(): string
{
    return (string) preg_replace('~/\*.*?\*/~s', ' ', (string) file_get_contents(resource_path('css/filament/student/theme.css')));
}

/** The body of the first rule whose selector list ends with exactly this selector, or null. */
function portalThemeRuleBody(string $css, string $selector): ?string
{
    // The selector is one of its rule's list: it starts the list or follows a comma, and a comma or the brace follows it.
    if (preg_match('/(?:[{};,]\s*)'.preg_quote($selector, '/').'\s*(?:,[^{}]*)?\{([^}]*)\}/', $css, $match) === 1) {
        return $match[1];
    }

    return null;
}

it('keeps the navy sidebar on hover and keyboard focus by out-specifying the framework rule', function (string $selector, string $background) {
    $body = portalThemeRuleBody(portalThemeCss(), $selector);

    expect($body)->not->toBeNull("No rule for {$selector}.")
        ->and($body)->toContain("background: {$background};")
        ->and($body)->not->toContain('gray');
})->with([
    'a linked item' => ['.fi-panel-student .fi-sidebar-item.fi-sidebar-item-has-url > .fi-sidebar-item-btn:is(:hover, :focus-visible)', 'rgb(250 246 236 / .1)'],
    'the current item, which keeps its gold wash' => ['.fi-panel-student .fi-sidebar-item.fi-active.fi-sidebar-item-has-url > .fi-sidebar-item-btn:is(:hover, :focus-visible)', 'rgb(201 164 76 / .18)'],
]);

it('keeps the current item gold with its inline-start border', function () {
    $body = portalThemeRuleBody(portalThemeCss(), '.fi-panel-student .fi-sidebar-item.fi-active > .fi-sidebar-item-btn');

    expect($body)->not->toBeNull()
        ->and($body)->toContain('background: rgb(201 164 76 / .18);')
        ->and($body)->toContain('border-inline-start: 3px solid var(--color-portal-gold);');
});

it('keeps cream text on the other buttons the framework greys on hover', function (string $selector) {
    $body = portalThemeRuleBody(portalThemeCss(), $selector);

    expect($body)->not->toBeNull("No rule for {$selector}.")
        ->and($body)->toContain('background: rgb(250 246 236 / .1);')
        ->and($body)->toContain('color: var(--color-portal-cream);');
})->with([
    'the sidebar group trigger' => ['.fi-panel-student .fi-sidebar-group-dropdown-trigger-btn:is(:hover, :focus-visible)'],
    'the sidebar notifications button' => ['.fi-panel-student .fi-sidebar-database-notifications-btn:is(:hover, :focus-visible)'],
    'an icon button in the topbar or the sidebar header' => ['.fi-panel-student :is(.fi-topbar, .fi-sidebar-header) .fi-icon-btn:not(.fi-disabled):not([disabled]):is(:hover, :focus-visible)'],
]);

it('shows the whole emblem in the brand mark', function () {
    $body = portalThemeRuleBody(portalThemeCss(), '.tc-portal-brand__mark');

    expect($body)->not->toBeNull()
        ->and($body)->toContain('object-fit: contain;')
        ->and($body)->not->toContain('cover')
        ->and($body)->toContain('border: 2px solid var(--color-portal-gold);');
});

it('names the Arabic family only under an Arabic page', function () {
    $css = portalThemeCss();

    $base = portalThemeRuleBody($css, '.fi-body.fi-panel-student');
    $arabic = portalThemeRuleBody($css, '.fi-body.fi-panel-student:lang(ar)');

    expect($base)->not->toBeNull()
        ->and($base)->not->toContain('font-family')
        ->and($arabic)->not->toBeNull()
        ->and($arabic)->toContain("font-family: 'IBM Plex Sans Arabic'")
        // Nothing else in the sheet names it outside the Arabic rule.
        ->and(substr_count($css, "'IBM Plex Sans Arabic'"))->toBe(1);
});

it('loads the Arabic font only for an Arabic page, and the display face always', function () {
    $code = (string) preg_replace('~/\*.*?\*/~s', ' ', (string) file_get_contents(app_path('Providers/Filament/StudentPanelProvider.php')));

    // The hook is guarded by the built stylesheet (CI runs before assets are built), and
    // inside it the font list depends on the locale, mirroring AdminPanelProvider.
    expect(preg_match('/Vite::fonts\(\s*app\(\)->getLocale\(\)\s*===\s*\'ar\'\s*\?\s*\[\'instrument-serif\',\s*\'ibm-plex-sans-arabic\'\]\s*:\s*\[\'instrument-serif\'\]\s*\)/', $code))->toBe(1)
        ->and(substr_count($code, 'Vite::fonts('))->toBe(1)
        ->and(substr_count($code, 'ibm-plex-sans-arabic'))->toBe(1);
});
