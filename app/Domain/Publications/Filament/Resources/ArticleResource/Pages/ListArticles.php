<?php

declare(strict_types=1);

namespace App\Domain\Publications\Filament\Resources\ArticleResource\Pages;

use App\Domain\Publications\Filament\Resources\ArticleResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

final class ListArticles extends ListRecords
{
    protected static string $resource = ArticleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // A link to the create page, not a CreateAction: the create page owns
            // the write, through CreateArticleAction.
            Action::make('create')
                ->label(__('publications.create_article'))
                ->visible(fn (): bool => ArticleResource::canCreate())
                ->url(fn (): string => ArticleResource::getUrl('create')),
        ];
    }
}
