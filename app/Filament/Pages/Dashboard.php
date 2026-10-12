<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Finance\Filament\Pages\EnrollAndCollect;
use App\Support\CentreCalendar;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Support\Icons\Heroicon;

class Dashboard extends BaseDashboard
{
    public static function getNavigationLabel(): string
    {
        return __('navigation.dashboard');
    }

    public function getTitle(): string
    {
        return __('navigation.front_desk');
    }

    public function getSubheading(): string
    {
        return __('navigation.dashboard_context', [
            'date' => now(CentreCalendar::TIMEZONE)->locale(app()->getLocale())->isoFormat(__('navigation.dashboard_date_format')),
            'centre' => Filament::getBrandName(),
        ]);
    }

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        if (! EnrollAndCollect::canAccess()) {
            return [];
        }

        return [
            Action::make('enrollAndCollect')
                ->label(__('collect.navigation_label'))
                ->icon(Heroicon::OutlinedPlus)
                ->url(EnrollAndCollect::getUrl()),
        ];
    }
}
