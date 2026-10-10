<?php

declare(strict_types=1);

namespace App\Domain\Publications\Filament\Resources\ArticleResource\Pages;

use App\Domain\Publications\Actions\UpdateArticleAction;
use App\Domain\Publications\Filament\Resources\ArticleResource;
use App\Domain\Publications\Models\Article;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

/**
 * Edits through the self-authorizing Action, never generic persistence.
 *
 * Filament's inherited handleRecordUpdate() is a bare `$record->update($data)`:
 * it would leave a replaced PDF on disk for ever and skip the slug-lock rule. The
 * Action replaces the file the way staff photos are replaced and owns the audit
 * attribution.
 *
 * NO HEADER ACTIONS, ON PURPOSE. In particular no DeleteAction: the library has
 * no delete path, and the Filament generator's default Edit page would have added
 * one. Publish and unpublish live on the list.
 *
 * Access is gated by EditRecord::authorizeAccess(): 403 unless the actor passes
 * ArticlePolicy::update().
 */
final class EditArticle extends EditRecord
{
    protected static string $resource = ArticleResource::class;

    /**
     * MUST be ?bool, as on CreateArticle. True so a refusal's Halt rolls the whole
     * save back rather than leaving part of it committed.
     */
    protected ?bool $hasDatabaseTransactions = true;

    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Article $record */
        return ArticleResource::attempt(fn (): Article => app(UpdateArticleAction::class)->execute(
            ArticleResource::actor(),
            $record,
            Arr::except($data, ['pdf_file']),
            ArticleResource::uploadedFileFrom($data),
        ));
    }
}
