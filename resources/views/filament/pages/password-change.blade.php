<x-filament-panels::page class="tc-password-change">
    <form wire:submit="save" class="flex flex-col gap-6">
        {{ $this->form }}
        <x-filament::button type="submit" class="w-full">
            {{ __('auth.update_password') }}
        </x-filament::button>
    </form>

    {{--
        THE WAY OFF THIS PAGE (G1-U3).

        The page renders no panel chrome, so the topbar user menu that normally
        carries logout is gone with it, and a flagged account is held here and
        nowhere else — without this the only exit is closing the browser.

        A plain HTML form, NOT a Filament action or any other Livewire control.
        Every Livewire component co-rendered here is another component that
        inherits this page's route in its snapshot and has to be refused by
        ForcePasswordChange; a POST that the browser makes on its own adds none.
        ForcePasswordChange exempts the logout route by name for this button.
    --}}
    <form method="POST" action="{{ filament()->getLogoutUrl() }}">
        @csrf

        <x-filament::button type="submit" color="gray" outlined class="w-full">
            {{ __('auth.log_out') }}
        </x-filament::button>
    </form>
</x-filament-panels::page>
