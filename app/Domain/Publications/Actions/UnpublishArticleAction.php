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
 * Withdraw an article from readers.
 *
 * Sets `published_at` back to null — the only way an article leaves the public
 * site, since nothing can be deleted. The record and its PDF stay, and the
 * download route, which re-reads publication state on every request, stops serving
 * the file at once.
 *
 * Unpublishing an article that is not published is refused, for the reason
 * PublishArticleAction refuses the mirror case. The row is locked and re-read
 * first so concurrent withdrawals serialise.
 *
 * A re-published article gets a NEW `published_at`; the first publication date is
 * not preserved on the row. The activity log keeps the history.
 */
final class UnpublishArticleAction
{
    public function __construct(private readonly CauserResolver $causers) {}

    /**
     * @throws AuthorizationException
     * @throws ValidationException if the article is not published.
     */
    public function execute(User $actor, Article $article): Article
    {
        Gate::forUser($actor)->authorize('unpublish', $article);

        return DB::transaction(function () use ($actor, $article): Article {
            $lockedArticle = Article::query()
                ->lockForUpdate()
                ->findOrFail($article->getKey());

            Gate::forUser($actor)->authorize('unpublish', $lockedArticle);

            if (! $lockedArticle->isPublished()) {
                throw ValidationException::withMessages([
                    'article' => __('publications.already_unpublished'),
                ]);
            }

            $this->causers->withCauser(
                $actor,
                fn (): bool => $lockedArticle->forceFill(['published_at' => null])->save(),
            );

            return $lockedArticle;
        });
    }
}
