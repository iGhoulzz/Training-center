<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Domain\Publications\Queries\PublicArticleCatalogue;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The publications library on the sessionless public site (P4-T13): the index,
 * with its search, topic chips and sorting, and one article's page.
 *
 * ONLY PUBLISHED ARTICLES, AND NO MODEL REACHES A VIEW. PublicArticleCatalogue
 * reads published rows only and hands over PublicArticle projections, so a
 * withdrawn article appears nowhere and a column added to `articles` later cannot
 * be published by default; see PublicArticle. There is no route-model binding here
 * for the same reason: a binding would load the row before anything asked whether
 * it is published.
 *
 * THE SEARCH BOX IS A READ-ONLY GET FORM. It writes `q`, `topic` and `sort` into
 * the URL and nothing else, so it needs neither a session nor a CSRF token
 * (docs/ENGINEERING.md, "Frontend (phase 4)"). Each of the three is read as text
 * and cleaned: an array, an over-long string or an unknown sort becomes the
 * default, never an error.
 */
final class PublicationController extends Controller
{
    public function __construct(private readonly PublicArticleCatalogue $catalogue) {}

    /**
     * The library: one page of articles, the topic chips, and the cleaned
     * search, topic and sort the form and the links keep.
     *
     * The paginator is unpacked into scalars and a list of projections, so the
     * view holds nothing but PublicArticle objects, strings, numbers and null.
     */
    public function index(Request $request): View
    {
        $search = PublicArticleCatalogue::cleanSearch($this->queryText($request, 'q'));
        $topic = PublicArticleCatalogue::cleanTopic($this->queryText($request, 'topic'));
        $sort = PublicArticleCatalogue::cleanSort($this->queryText($request, 'sort'));

        $page = $this->catalogue->page($search, $topic, $sort);

        return view('public.publications.index', [
            'articles' => array_values($page->items()),
            'total' => $page->total(),
            'currentPage' => $page->currentPage(),
            'lastPage' => $page->lastPage(),
            'previousUrl' => $page->previousPageUrl(),
            'nextUrl' => $page->nextPageUrl(),
            'topics' => $this->catalogue->topics(),
            'search' => $search,
            'topic' => $topic,
            'sort' => $sort,
        ]);
    }

    /**
     * One article's page.
     *
     * AN UNPUBLISHED ARTICLE AND AN UNKNOWN SLUG ARE THE SAME RESPONSE. The
     * catalogue answers both with null, and both get the same 404 page: a
     * different one would tell a visitor which articles the centre has withdrawn.
     */
    public function show(string $slug): View
    {
        $article = $this->catalogue->find($slug);

        abort_if($article === null, 404);

        return view('public.publications.show', [
            'article' => $article,
            'related' => $this->catalogue->moreInTopic($article->topic, $article->slug),
        ]);
    }

    /**
     * A query-string value as text: an array (`?q[]=x`) or a missing key is ''.
     */
    private function queryText(Request $request, string $key): string
    {
        $value = $request->query($key);

        return is_string($value) ? $value : '';
    }
}
