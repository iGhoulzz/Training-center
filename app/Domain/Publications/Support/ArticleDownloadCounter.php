<?php

declare(strict_types=1);

namespace App\Domain\Publications\Support;

use App\Domain\Publications\Models\Article;

/**
 * The one writer of `articles.download_count`.
 *
 * THIS IS NOT AN ACTION, AND DELIBERATELY DOES NOT CLAIM TO BE ONE
 * ----------------------------------------------------------------
 * Every security-sensitive write in this system goes through an actor-first
 * Action that authorizes the actor. A download is made by an anonymous reader:
 * there is no actor to pass and nothing to authorize. The owner approved this as
 * the single exception to the write-boundary rule, and docs/ENGINEERING.md, "The
 * one exception: the article download counter", states its bounds. They are
 * repeated here so the code and the standard cannot be read apart:
 *
 *   - ONE COLUMN: `articles.download_count`, and nothing else on any table. Not
 *     even `updated_at`.
 *   - ONE CLASS: this one.
 *   - INCREMENT ONLY, by a single conditional statement:
 *       UPDATE articles SET download_count = download_count + 1
 *       WHERE id = ? AND published_at IS NOT NULL
 *     No read-modify-write, no decrement, no reset. An unpublished article is
 *     never counted.
 *   - NOT SECURITY-SENSITIVE: it moves no money, grants no access and exposes
 *     nothing. The worst a forged count can do is reorder a "most downloaded"
 *     sort, which is labelled as an approximate count.
 *   - NO ACTIVITY-LOG ROW: an anonymous count has no actor to attribute, and
 *     logging every download would flood an append-only log with rows nobody can
 *     explain.
 *   - ENFORCED, NOT TRUSTED: ArticleWriteBoundaryArchTest fails when any other
 *     file under app/ writes the column, and `download_count` is absent from
 *     Article::$fillable.
 *
 * This exception is not precedent. A second actor-less write needs its own owner
 * decision and its own entry in ENGINEERING.md. "The counter does it" is not a
 * reason.
 *
 * THE COUNT IS APPROXIMATE BY NATURE. It is rate-limited, not deduplicated per
 * reader.
 */
final class ArticleDownloadCounter
{
    /**
     * Count one download of a published article.
     *
     * Returns whether a row changed: false means the article is unpublished (or
     * no longer exists), and nothing was counted.
     *
     * `toBase()` is deliberate. The Eloquent builder's increment() appends
     * `updated_at` to the statement, which would make every anonymous download
     * look like an edit of the article. The base query builder writes only the
     * counter, which is the whole of what the exception permits.
     *
     * The caller's `$article` is used for its key alone. Its in-memory
     * `download_count` and `published_at` are never trusted: the WHERE clause
     * re-reads publication state inside the statement itself, so a stale
     * instance cannot count a download of something since withdrawn, and two
     * stale instances of one article still add two.
     */
    public function increment(Article $article): bool
    {
        return Article::query()
            ->whereKey($article->getKey())
            ->whereNotNull('published_at')
            ->toBase()
            ->increment('download_count') > 0;
    }
}
