<?php

declare(strict_types=1);

namespace App\Domain\Publications\Actions;

use App\Domain\Publications\Models\Article;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Make an article visible to readers.
 *
 * Sets `published_at` to now — the only thing that does. The row is locked and
 * re-read before the check, so two administrators publishing the same article at
 * once serialise: the second finds it already published and is refused, instead
 * of both passing an unlocked read and the later one moving the timestamp.
 *
 * Publishing an article that is already published is refused rather than
 * silently succeeding. A no-op that overwrote `published_at` would erase the
 * date readers were told, and one that succeeded without writing would tell an
 * administrator something happened when nothing did.
 *
 * `published_at` is not fillable on Article, so it is set with forceFill() here
 * and in UnpublishArticleAction and nowhere else; the act of publishing stays
 * deliberate and attributable. The audit entry is the model's own `updated`
 * event, whose diff carries the old and new value of `published_at`.
 *
 * The file is not checked: a missing PDF is a storage fault the download route
 * reports, and refusing to publish would tell an administrator nothing they can
 * act on from this screen.
 */
final class PublishArticleAction
{
    public function __construct(private readonly CauserResolver $causers) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException if the article is already published.
     */
    public function execute(User $actor, Article $article): Article
    {
        Gate::forUser($actor)->authorize('publish', $article);

        return DB::transaction(function () use ($actor, $article): Article {
            // A locking read is a CURRENT read. An ordinary read here would come
            // from the transaction's snapshot, which the permission lookup above
            // may already have established.
            $lockedArticle = Article::query()
                ->lockForUpdate()
                ->findOrFail($article->getKey());

            Gate::forUser($actor)->authorize('publish', $lockedArticle);

            if ($lockedArticle->isPublished()) {
                throw ValidationException::withMessages([
                    'article' => __('publications.already_published'),
                ]);
            }

            $this->causers->withCauser(
                $actor,
                fn (): bool => $lockedArticle->forceFill(['published_at' => now()])->save(),
            );

            return $lockedArticle;
        });
    }
}
