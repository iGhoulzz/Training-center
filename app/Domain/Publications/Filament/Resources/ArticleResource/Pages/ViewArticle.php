<?php

declare(strict_types=1);

namespace App\Domain\Publications\Filament\Resources\ArticleResource\Pages;

use App\Domain\Publications\Filament\Resources\ArticleResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

final class ViewArticle extends ViewRecord
{
    protected static string $resource = ArticleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // A link to the edit page, gated on update_article. Not an EditAction,
            // and no DeleteAction: the library has no delete path.
            Action::make('edit')
                ->label(__('publications.edit_article'))
                ->icon(Heroicon::OutlinedPencilSquare)
                ->authorize('update')
                ->url(fn (): string => ArticleResource::getUrl('edit', ['record' => $this->getRecord()])),
        ];
    }
}
