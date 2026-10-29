<?php

declare(strict_types=1);

namespace App\Domain\Finance\Filament\Resources\PayrollRunResource\Pages;

use App\Domain\Finance\Filament\Resources\PayrollRunResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Schema;

final class ListPayrollRuns extends ListRecords
{
    protected static string $resource = PayrollRunResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label(__('payroll.create_run'))
                ->tooltip(__('payroll.tooltip_create_run'))
                ->visible(fn (): bool => PayrollRunResource::canCreate())
                ->url(fn (): string => PayrollRunResource::getUrl('create')),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([PayrollRunResource::listHelpPanel(), ...parent::content($schema)->getComponents()]);
    }
}
