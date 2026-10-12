<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Route;
use LogicException;

/**
 * The only way a public view builds a link to another public page.
 *
 * WHY A BARE route() IS THE DEFECT THIS EXISTS TO PREVENT
 * -------------------------------------------------------
 * T18 adds Arabic copies of the public pages, named `ar.` plus the English name
 * (`ar.public.home` beside `public.home`). The names have to differ: registering
 * the Arabic route under the English name would overwrite the English entry in
 * the router's name lookup, and every English link would then point at Arabic.
 *
 * So `route('public.home')` always means the English page. In a view an Arabic
 * visitor is reading, it would send them back to English on the first click, and
 * nothing would look broken: the link works, the page renders. This helper
 * chooses the route that matches the request's locale, and PublicRouteTest scans
 * resources/views/public/ and the public layout for a bare `route(` so the pages
 * T12, T13 and T15 write inherit the rule without restating it.
 *
 * IT NEVER FALLS BACK TO ENGLISH. A locale with no matching route means a page
 * was added in English and its Arabic twin was forgotten. Returning the English
 * URL would hide that behind a working link, which is exactly the silent
 * redirect-to-English the paragraph above describes. A LogicException names both
 * routes instead, so the omission fails the first test that renders the page.
 *
 * Until T18 the locale is always English, so this behaves as `route()`. T18 adds
 * routes and a locale, and never has to edit this class.
 */
final class PublicRoute
{
    /**
     * The locale whose routes keep their plain names.
     */
    public const ENGLISH_LOCALE = 'en';

    /**
     * The URL of a public page, in the request's locale.
     *
     * @param  array<string, mixed>  $parameters
     *
     * @throws LogicException when the locale is not English and no `{locale}.{name}` route exists.
     */
    public static function url(string $name, array $parameters = []): string
    {
        $locale = app()->getLocale();

        if ($locale === self::ENGLISH_LOCALE) {
            return route($name, $parameters);
        }

        $localised = "{$locale}.{$name}";

        if (! Route::has($localised)) {
            throw new LogicException(
                "No route [{$localised}] exists for the [{$locale}] locale, so [{$name}] cannot be linked to. "
                .'Register it beside the English route rather than falling back to English.',
            );
        }

        return route($localised, $parameters);
    }
}
