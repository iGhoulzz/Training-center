<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Domain\Enrollment\Queries\PublicProgrammeCatalogue;
use App\Domain\Publications\Queries\PublicArticleCatalogue;
use App\Http\Controllers\Controller;
use Illuminate\View\View;

/**
 * Home, about and contact on the sessionless public site (P4-T12).
 *
 * The three pages hold no form and read nothing about the visitor, so none of
 * them can vary by who is asking. The home page takes its first few programme
 * cards from the same catalogue the programmes page lists, and its teaser takes
 * the latest published articles from the catalogue the library lists (P4-T20b).
 * The contact details are not passed in: the email-link and phone-link components
 * and the contact page read PublicContact themselves.
 */
final class PageController extends Controller
{
    /** How many programme cards the home page shows before it links on to the rest. */
    private const HOME_PROGRAMME_LIMIT = 4;

    /** How many articles the home page's publications teaser shows before it links on to the library. */
    private const HOME_ARTICLE_LIMIT = 3;

    public function __construct(
        private readonly PublicProgrammeCatalogue $catalogue,
        private readonly PublicArticleCatalogue $articles,
    ) {}

    public function home(): View
    {
        return view('public.home', [
            'programmes' => array_slice($this->catalogue->cards(), 0, self::HOME_PROGRAMME_LIMIT),
            'articles' => $this->articles->latest(self::HOME_ARTICLE_LIMIT),
        ]);
    }

    public function about(): View
    {
        return view('public.about');
    }

    public function contact(): View
    {
        return view('public.contact');
    }
}
