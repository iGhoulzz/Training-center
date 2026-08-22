<x-filament-panels::page>
    {{ $this->form }}

    {{-- Steps 6-8 (P2-T09, unit 3): a separate schema, rendered only once
         confirm() has raised a bill and opened it for an actor holding
         create_payment — see EnrollAndCollect's own class docblock. --}}
    @if ($this->collectionChargeId !== null)
        {{ $this->collectForm }}
    @endif
</x-filament-panels::page>
