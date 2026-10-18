<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\Middleware\SetLocale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use LogicException;

/**
 * The only way a public view builds a link to another public page.
 *
 * WHY A BARE route() IS THE DEFECT THIS EXISTS TO PREVENT
 * -------------------------------------------------------
 * The Arabic copies of the public pages are named `ar.` plus the English name
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
 * THE COUNTERPART (P4-T18a). The language switch and the `hreflang` links in the
 * head need the page the visitor is on, in the other language. counterpart() reads
 * the request's own route, strips the locale prefix from its name, and builds the
 * same route in the locale asked for, from the same parameters and the same query
 * string. It is built from the ROUTE and not from the URL, for the reason above: a
 * page whose twin was forgotten throws here too, and a string replace on the path
 * would link to a 404.
 *
 * ONE RULE FOR WHICH LOCALE A ROUTE BELONGS TO, kept here so the middleware that
 * sets the locale (SetPublicLocale) and the helper that builds links can never
 * disagree about it: a name starting `{locale}.` for a supported non-English
 * locale is that locale's, and every other name is English.
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
        return self::urlIn(app()->getLocale(), $name, $parameters);
    }

    /**
     * The URL of a public page, in the locale asked for.
     *
     * @param  array<string, mixed>  $parameters
     *
     * @throws LogicException when the locale is not English and no `{locale}.{name}` route exists.
     */
    public static function urlIn(string $locale, string $name, array $parameters = []): string
    {
        if ($locale === self::ENGLISH_LOCALE) {
            return route($name, $parameters);
        }

        $localised = self::nameIn($locale, $name);

        if (! Route::has($localised)) {
            throw new LogicException(
                "No route [{$localised}] exists for the [{$locale}] locale, so [{$name}] cannot be linked to. "
                .'Register it beside the English route rather than falling back to English.',
            );
        }

        return route($localised, $parameters);
    }

    /**
     * The route name of an English-named route in a locale: the name itself in
     * English, `{locale}.{name}` in any other.
     */
    public static function nameIn(string $locale, string $name): string
    {
        return $locale === self::ENGLISH_LOCALE ? $name : "{$locale}.{$name}";
    }

    /**
     * The locale a route name belongs to. English for a null, an unnamed or an
     * English-named route.
     */
    public static function localeOf(?string $routeName): string
    {
        foreach (SetLocale::SUPPORTED as $locale) {
            if ($locale !== self::ENGLISH_LOCALE && is_string($routeName) && str_starts_with($routeName, "{$locale}.")) {
                return $locale;
            }
        }

        return self::ENGLISH_LOCALE;
    }

    /**
     * A route's English name: `ar.public.home` and `public.home` both give
     * `public.home`.
     */
    public static function baseName(string $routeName): string
    {
        $locale = self::localeOf($routeName);

        return $locale === self::ENGLISH_LOCALE ? $routeName : substr($routeName, strlen($locale) + 1);
    }

    /**
     * The page the request is on, in another locale: the same route, the same
     * parameters and the same query string.
     *
     * Asked for the request's own locale it returns the request's own URL, which is
     * how alternates() lists every language.
     *
     * @throws LogicException when the request has no named route, or when the route has
     *                        no twin in that locale.
     */
    public static function counterpart(string $locale, ?Request $request = null): string
    {
        $request ??= request();
        $route = $request->route();
        $name = $route?->getName();

        if (! is_string($name)) {
            throw new LogicException('The request is not on a named route, so it has no counterpart in another locale.');
        }

        // The raw segments of the URL, not the bound models: a model would be turned
        // back into its key, and the key is what the segment already is.
        $url = self::urlIn($locale, self::baseName($name), $route->originalParameters());
        $query = $request->getQueryString();

        return $query === null || $query === '' ? $url : "{$url}?{$query}";
    }

    /**
     * The page in every supported locale, keyed by locale, for the head's
     * `<link rel="alternate" hreflang>` list. Empty when the request is not on a
     * named route, which has no pages to list.
     *
     * @return array<string, string>
     */
    public static function alternates(?Request $request = null): array
    {
        $request ??= request();

        if (! is_string($request->route()?->getName())) {
            return [];
        }

        $alternates = [];

        foreach (SetLocale::SUPPORTED as $locale) {
            $alternates[$locale] = self::counterpart($locale, $request);
        }

        return $alternates;
    }
}
