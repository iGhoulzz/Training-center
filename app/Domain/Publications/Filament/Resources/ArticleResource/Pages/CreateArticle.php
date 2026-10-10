<?php

declare(strict_types=1);

namespace App\Domain\Publications\Filament\Resources\ArticleResource\Pages;

use App\Domain\Publications\Actions\CreateArticleAction;
use App\Domain\Publications\Filament\Resources\ArticleResource;
use App\Domain\Publications\Models\Article;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use RuntimeException;

/**
 * Creates through the self-authorizing Action, never generic persistence.
 *
 * Filament's inherited handleRecordCreation() is a bare `new $model($data)`: it
 * would let the form choose disk, path and original filename. The Action derives
 * all three from the uploaded file.
 *
 * Access is gated by CreateRecord::authorizeAccess(), which aborts 403 unless the
 * actor passes ArticlePolicy::create().
 */
final class CreateArticle extends CreateRecord
{
    protected static string $resource = ArticleResource::class;

    /**
     * MUST be ?bool. Filament declares it ?bool in
     * Filament\Pages\Concerns\CanUseDatabaseTransactions; narrowing to bool is a
     * fatal type error. It is true so a refusal's Halt can roll the whole save
     * back.
     */
    protected ?bool $hasDatabaseTransactions = true;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return ArticleResource::attempt(function () use ($data): Article {
            $file = ArticleResource::uploadedFileFrom($data)
                // The field is required and validated by Filament before this
                // runs, so a missing file here is a wiring fault, not user input.
                ?? throw new RuntimeException('Article creation reached the Action without a file.');

            return app(CreateArticleAction::class)->execute(
                ArticleResource::actor(),
                Arr::except($data, ['pdf_file']),
                $file,
            );
        });
    }
}
