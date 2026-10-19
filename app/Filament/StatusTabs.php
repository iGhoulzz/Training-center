<?php

declare(strict_types=1);

namespace App\Filament;

use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Tabs\Tab;

trait StatusTabs
{
    /**
     * @param  array<string, Tab>  $tabs
     * @param  array<string, string>  $dots
     * @return array<string, Tab>
     */
    protected function styleStatusTabs(array $tabs, array $dots): array
    {
        foreach ($tabs as $key => $tab) {
            $tab->extraAttributes(fn (): array => ['class' => implode(' ', [
                "rounded-full border px-[13px] py-[7px] text-[12.5px] font-medium gap-[7px] focus-visible:ring-2 focus-visible:ring-primary-400 focus-visible:ring-offset-2 focus-visible:ring-offset-gray-950 before:size-[6px] before:shrink-0 before:rounded-full before:content-['']",
                $dots[$key] ?? 'before:bg-gray-400',
                $this->activeTab === $key
                    ? 'border-primary-500/40 bg-primary-500/14 text-primary-400 hover:bg-primary-500/14 focus-visible:bg-primary-500/14'
                    : 'border-white/9 bg-white/4 text-gray-400 hover:bg-white/4 focus-visible:bg-white/4',
            ])]);
        }

        return $tabs;
    }

    public function getTabsContentComponent(): Component
    {
        return parent::getTabsContentComponent()->extraAttributes([
            'class' => '[&_.fi-tabs]:mx-0 [&_.fi-tabs]:flex-wrap [&_.fi-tabs]:gap-2 [&_.fi-tabs]:overflow-visible [&_.fi-tabs]:rounded-none [&_.fi-tabs]:bg-transparent [&_.fi-tabs]:p-0 [&_.fi-tabs]:shadow-none [&_.fi-tabs]:ring-0 [&_.fi-tabs-item-label]:text-inherit [&_.fi-badge]:min-w-0 [&_.fi-badge]:bg-transparent [&_.fi-badge]:p-0 [&_.fi-badge]:text-[12.5px] [&_.fi-badge]:tracking-normal [&_.fi-badge]:text-inherit [&_.fi-badge]:opacity-74 [&_.fi-badge]:ring-0',
        ]);
    }
}
