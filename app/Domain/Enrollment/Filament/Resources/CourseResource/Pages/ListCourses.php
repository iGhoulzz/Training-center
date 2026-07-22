<?php

declare(strict_types=1);

namespace App\Domain\Enrollment\Filament\Resources\CourseResource\Pages;

use App\Domain\Enrollment\Filament\Resources\CourseResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListCourses extends ListRecords
{
    protected static string $resource = CourseResource::class;

    /**
     * A plain link Action, deliberately NOT Filament's CreateAction — the same
     * reasoning as ListStudents: CreateAction keeps a mountable server-side
     * create handler even when ->url() is set, and that handler persists with a
     * bare $model::create($data) that never runs the create page's hooks.
     *
     * canCreate() is checked explicitly because a plain Action carries no
     * authorization of its own; without it the button renders for view-only
     * staff, who then hit a 403 on the page behind it.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label(__('enrollment.create_course'))
                ->visible(fn (): bool => CourseResource::canCreate())
                ->url(fn (): string => CourseResource::getUrl('create')),
        ];
    }
}
