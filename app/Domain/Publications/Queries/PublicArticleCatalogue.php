<?php

declare(strict_types=1);

namespace App\Domain\Publications\Queries;

use App\Domain\Publications\Data\PublicArticle;
use App\Domain\Publications\Models\Article;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * The published articles the public library lists, as closed projections (P4-T13).
 *
 * NOTHING UNPUBLISHED IS EVER READ. Every query starts from Article::published(),
 * the one definition of "published", so a withdrawn article cannot appear in the
 * list, in a topic chip's count, in a search result or in "more in this topic".
 * find() states the same condition in the same query that looks the slug up, so an
 * unpublished slug and an unknown one are both null, and the page answers both with
 * the same 404.
 *
 * NO MODEL LEAVES THIS CLASS. Every article is turned into a PublicArticle by the
 * one private method below, which names each property, so a column added to
 * `articles` later cannot reach a public page by default. See PublicArticle.
 *
 * A CONSTANT NUMBER OF QUERIES. The index is a count and a page (page()) plus one
 * GROUP BY for the chips (topics()); a card never queries. Two articles on the
 * page or twelve run the same three statements.
 *
 * SEARCH IS LITERAL TEXT, NOT A PATTERN. The visitor's words are matched with
 * LIKE against the title, description, topic and authors, in both languages, and
 * `%`, `_` and `\` are escaped first, so "50%" finds "50%" and not everything that
 * starts with 50.
 */
final class PublicArticleCatalogue
{
    /** The page size of the index. */
    public const PER_PAGE = 12;

    /** The longest search text read. Anything past it is cut, not refused. */
    public const SEARCH_MAX_LENGTH = 100;

    /** The longest topic read: the column's own width, so a longer value cannot match anything. */
    public const TOPIC_MAX_LENGTH = 100;

    /** Newest issue date first; the default. */
    public const SORT_NEWEST = 'newest';

    /** Most downloaded first. The count is approximate; see ArticleDownloadCounter. */
    public const SORT_DOWNLOADS = 'downloads';

    /** How many other articles of the same topic an article page offers. */
    public const MORE_IN_TOPIC_LIMIT = 3;

    /** The columns the search matches: both languages of the title and the description, the topic and the authors. */
    private const SEARCH_COLUMNS = ['title_en', 'title_ar', 'description_en', 'description_ar', 'topic', 'authors'];

    /** The only columns of an article the projection reads. */
    private const COLUMNS = ['slug', 'title_en', 'title_ar', 'description_en', 'description_ar', 'topic', 'authors', 'issued_on', 'download_count'];

    /**
     * The visitor's search text, made safe to use: scrubbed of invalid UTF-8,
     * trimmed, and cut to SEARCH_MAX_LENGTH characters.
     */
    public static function cleanSearch(string $raw): string
    {
        return self::clean($raw, self::SEARCH_MAX_LENGTH);
    }

    /** The topic filter as read: scrubbed, trimmed and cut to the column's width. */
    public static function cleanTopic(string $raw): string
    {
        return self::clean($raw, self::TOPIC_MAX_LENGTH);
    }

    /** A known sort, or the default for anything else. */
    public static function cleanSort(string $raw): string
    {
        return $raw === self::SORT_DOWNLOADS ? self::SORT_DOWNLOADS : self::SORT_NEWEST;
    }

    /**
     * One page of the published articles, filtered, searched and sorted.
     *
     * The arguments are cleaned again here, so a caller that skipped the
     * clean*() methods above still cannot run an unbounded LIKE or an unknown
     * sort. The paginator keeps the request's query string on its links.
     *
     * @return LengthAwarePaginator<int, PublicArticle>
     */
    public function page(string $search, string $topic, string $sort): LengthAwarePaginator
    {
        $search = self::cleanSearch($search);
        $topic = self::cleanTopic($topic);

        $query = Article::query()->published();

        if ($topic !== '') {
            $query->where('topic', $topic);
        }

        if ($search !== '') {
            $pattern = '%'.addcslashes($search, '%_\\').'%';

            $query->where(function (Builder $matches) use ($pattern): void {
                foreach (self::SEARCH_COLUMNS as $column) {
                    $matches->orWhere($column, 'like', $pattern);
                }
            });
        }

        if (self::cleanSort($sort) === self::SORT_DOWNLOADS) {
            // Ties fall back to the issue date and then the id, so the order never
            // depends on how the database happened to return equal counts.
            $query->orderByDesc('download_count')->orderByDesc('issued_on')->orderByDesc('id');
        } else {
            $query->orderByDesc('issued_on')->orderByDesc('published_at')->orderByDesc('id');
        }

        return $query
            ->paginate(self::PER_PAGE, self::COLUMNS)
            ->withQueryString()
            ->through(fn (Article $article): PublicArticle => $this->project($article));
    }

    /**
     * Every topic that has a published article, with how many, in alphabetical order.
     *
     * ONE GROUP BY over the published articles, so the chips cost one query
     * however many topics there are. The counts are the whole library's, not the
     * current search's: a chip says how much a topic holds.
     *
     * @return list<array{topic: string, count: int}>
     */
    public function topics(): array
    {
        return Article::query()
            ->published()
            ->toBase()
            ->selectRaw('topic, count(*) as total')
            ->groupBy('topic')
            ->orderBy('topic')
            ->get()
            ->map(fn (object $row): array => ['topic' => (string) $row->topic, 'count' => (int) $row->total])
            ->values()
            ->all();
    }

    /**
     * One article, or null for a slug that is unpublished OR unknown.
     *
     * The two are the same answer on purpose: the page answers both with the same
     * 404, so nothing here can tell a caller which slugs the centre once published.
     * An unpublished row is never loaded into a projection.
     */
    public function find(string $slug): ?PublicArticle
    {
        $article = Article::query()
            ->published()
            ->where('slug', $slug)
            ->first(self::COLUMNS);

        return $article instanceof Article ? $this->project($article) : null;
    }

    /**
     * Up to $limit other published articles in the same topic, newest first.
     *
     * @return list<PublicArticle>
     */
    public function moreInTopic(string $topic, string $exceptSlug, int $limit = self::MORE_IN_TOPIC_LIMIT): array
    {
        return Article::query()
            ->published()
            ->where('topic', $topic)
            ->where('slug', '!=', $exceptSlug)
            ->orderByDesc('issued_on')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(self::COLUMNS)
            ->map(fn (Article $article): PublicArticle => $this->project($article))
            ->values()
            ->all();
    }

    /** Invalid UTF-8 replaced, whitespace trimmed, cut to $maxLength characters, and trimmed again. */
    private static function clean(string $raw, int $maxLength): string
    {
        return trim(mb_substr(trim(mb_scrub($raw)), 0, $maxLength));
    }

    /**
     * The one place an article becomes a public one: each property named, and
     * nothing handed over wholesale.
     */
    private function project(Article $article): PublicArticle
    {
        return new PublicArticle(
            slug: (string) $article->slug,
            title: $article->title(),
            description: $article->description(),
            topic: (string) $article->topic,
            authors: (string) $article->authors,
            issuedOn: $article->issued_on->format('Y-m-d'),
            downloadCount: (int) $article->download_count,
        );
    }
}
