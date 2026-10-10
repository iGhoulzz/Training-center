<?php

declare(strict_types=1);

namespace App\Domain\Publications\Actions;

use App\Domain\Publications\Models\Article;
use App\Domain\Staff\Exceptions\FileStorageException;
use App\Domain\Staff\Services\FileLifecycleService;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Edit an article's details and, optionally, replace its PDF.
 *
 * Authorizes `update` — not `publish`. Correcting a published article is an
 * editor's act; making one public or taking it down is a separate grant. This
 * Action therefore never touches `published_at`, and the lifecycle Actions never
 * touch anything else.
 *
 * THE SLUG IS FIXED ONCE PUBLISHED
 * --------------------------------
 * The slug is the article's public address. Changing it while published would
 * break every link already shared, so a different slug is refused for a published
 * article and accepted only while it is unpublished. An omitted slug is left as
 * it is, which is what a read-only form field sends.
 *
 * REPLACE MEANS COMMIT FIRST, THEN DESTROY
 * ----------------------------------------
 * The new file is written, the new path is committed, and only then is the old
 * file scheduled for deletion — the order UpdateStaffPhotoAction records. The
 * receipt for the file being replaced is written in the same transaction as the
 * new path, so "the article points here now" and "destroy what it pointed at
 * before" commit together. Destroying the old file first would leave a published
 * article pointing at nothing if the save then failed.
 *
 * The old path is read from the LOCKED row, never from the caller's in-memory
 * article: two requests can hold different snapshots, and locking and reloading
 * here makes the second replacement purge the first replacement rather than
 * purging the original twice.
 *
 * STORAGE IDENTITY IS SERVER-OWNED, exactly as CreateArticleAction states it.
 */
final class UpdateArticleAction
{
    public function __construct(
        private readonly FileLifecycleService $files,
        private readonly CauserResolver $causers,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata  title_en, title_ar, description_en,
     *                                          description_ar, topic, authors, issued_on and optionally slug.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     * @throws FileStorageException if the disk reports the write failed.
     */
    public function execute(User $actor, Article $article, array $metadata, ?UploadedFile $file = null): Article
    {
        Gate::forUser($actor)->authorize('update', $article);

        $attributes = CreateArticleAction::validateMetadata($metadata);

        if (array_key_exists('slug', $metadata)) {
            $attributes['slug'] = $this->validatedSlug($metadata['slug'], $article);
        }

        if ($file === null) {
            return DB::transaction(
                fn (): Article => $this->apply($actor, $article, $attributes, null)['article'],
            );
        }

        CreateArticleAction::validateFile($file);

        $path = CreateArticleAction::DIRECTORY.'/'.Str::ulid()->toString().'.'.CreateArticleAction::EXTENSION;

        $replacement = ['path' => $path, 'original_filename' => CreateArticleAction::displayName($file)];

        /** @var array{article: Article, pendingIds: array<int, int>} $result */
        $result = $this->files->persistNewFile(
            CreateArticleAction::DISK,
            $path,
            function () use ($file, $path): void {
                $this->store($file, $path);
            },
            fn (): array => $this->apply($actor, $article, $attributes, $replacement),
        );

        $this->files->dispatchPurges($result['pendingIds']);

        return $result['article'];
    }

    /**
     * Lock the row, re-check, and write — inside a transaction the caller opened.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array{path: string, original_filename: string}|null  $replacement
     * @return array{article: Article, pendingIds: array<int, int>}
     */
    private function apply(User $actor, Article $article, array $attributes, ?array $replacement): array
    {
        $lockedArticle = Article::query()
            ->lockForUpdate()
            ->findOrFail($article->getKey());

        // The first check fails fast; this one remains correct if a future policy
        // starts consulting mutable article state.
        Gate::forUser($actor)->authorize('update', $lockedArticle);

        if (
            array_key_exists('slug', $attributes)
            && $attributes['slug'] !== $lockedArticle->slug
            && $lockedArticle->isPublished()
        ) {
            throw ValidationException::withMessages([
                'slug' => __('publications.slug_locked'),
            ]);
        }

        $pendingIds = [];

        if ($replacement !== null) {
            $pendingIds = $this->files->record([[
                'disk' => $lockedArticle->disk,
                'path' => $lockedArticle->path,
            ]]);

            $attributes = [
                ...$attributes,
                'disk' => CreateArticleAction::DISK,
                'path' => $replacement['path'],
                'original_filename' => $replacement['original_filename'],
            ];
        }

        $this->causers->withCauser(
            $actor,
            fn (): bool => $lockedArticle->update($attributes),
        );

        return ['article' => $lockedArticle, 'pendingIds' => $pendingIds];
    }

    /**
     * @throws ValidationException
     */
    private function validatedSlug(mixed $slug, Article $article): string
    {
        /** @var array{slug: string} $validated */
        $validated = Validator::make(
            ['slug' => is_string($slug) ? Str::slug($slug) : $slug],
            [
                'slug' => [
                    'required',
                    'string',
                    'max:'.CreateArticleAction::SLUG_MAX_LENGTH,
                    Rule::unique(Article::class, 'slug')->ignore($article->getKey()),
                ],
            ],
            [],
            CreateArticleAction::attributeNames(),
        )->validate();

        return $validated['slug'];
    }

    /**
     * @throws FileStorageException
     */
    private function store(UploadedFile $file, string $path): void
    {
        $storedPath = Storage::disk(CreateArticleAction::DISK)->putFileAs(
            CreateArticleAction::DIRECTORY,
            $file,
            basename($path),
        );

        if ($storedPath === false || $storedPath !== $path) {
            throw FileStorageException::writeFailed(CreateArticleAction::DISK, $path);
        }
    }
}
