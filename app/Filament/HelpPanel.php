<?php

declare(strict_types=1);

namespace App\Filament;

use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Support\Icons\Heroicon;

/**
 * The "How to read this page" panel (P4-T37), shared by the Charges list, the
 * payroll pages and the compensation pages.
 *
 * Collapsible and OPEN by default. `persistCollapsed()` keeps a collapse in the
 * browser (Alpine's `$persist`, keyed on the section id), so there is no server
 * column and the id must be stable and unique per page. Display only: nothing
 * here reads or writes a record.
 */
final class HelpPanel
{
    /**
     * @param  string  $id  Stable and unique per page: the browser remembers open or closed under it.
     * @param  string  $heading  The already-translated heading.
     * @param  string  $view  The Blade view holding the panel's body.
     * @param  array<string, mixed>  $viewData
     */
    public static function make(string $id, string $heading, string $view, array $viewData = []): Section
    {
        return Section::make($heading)
            ->id($id)
            ->icon(Heroicon::OutlinedInformationCircle)
            ->collapsible()
            ->persistCollapsed()
            ->schema([View::make($view)->viewData($viewData)])
            ->extraAttributes(['class' => 'rounded-xl! [&_.fi-section-content]:p-3.75!']);
    }
}
