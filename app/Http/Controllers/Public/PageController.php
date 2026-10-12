<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

/**
 * Home, about and contact on the sessionless public site (P4-T12).
 *
 * The three pages hold no form and read nothing about the visitor, so none of
 * them can vary by who is asking. The programme list belongs to ProgrammeController
 * and the home page borrows its first few cards from there.
 */
final class PageController extends Controller
{
    /** How many programme cards the home page shows before it links on to the rest. */
    private const HOME_PROGRAMME_LIMIT = 4;

    public function home(ProgrammeController $programmes): View
    {
        return view('public.home', [
            'programmes' => array_slice($programmes->catalogue(), 0, self::HOME_PROGRAMME_LIMIT),
            'contact' => $this->contactDetails(),
        ]);
    }

    public function about(): View
    {
        return view('public.about');
    }

    public function contact(): View
    {
        return view('public.contact', ['contact' => $this->contactDetails()]);
    }

    /**
     * What the centre publishes for reaching it, prepared once for both pages.
     *
     * THE EMAIL AND TELEPHONE ARE BUILT EXACTLY AS THE HEADER BUILDS THEM
     * (resources/views/public/partials/header.blade.php): both trimmed, and a tel:
     * link that keeps the digits and a leading plus while the visible text keeps
     * whatever separators the configured value was written with. The header keeps
     * its own copy of this rule, so a change to one is a change to the other.
     *
     * The map link is passed through only when it is a plain http(s) URL, so a
     * blank value turns it off and a mistyped one is never put in an href.
     *
     * @return array{email: string, phone: string, telephone: string, map_url: string}
     */
    private function contactDetails(): array
    {
        $email = trim((string) config('public_site.contact.email'));
        $phone = trim((string) config('public_site.contact.phone'));
        $mapUrl = trim((string) config('public_site.contact.map_url'));

        return [
            'email' => $email,
            'phone' => $phone,
            'telephone' => (str_starts_with($phone, '+') ? '+' : '').(string) preg_replace('/\D/', '', $phone),
            'map_url' => preg_match('#^https?://#i', $mapUrl) === 1 ? $mapUrl : '',
        ];
    }
}
