<x-filament-panels::page>
    <form wire:submit="applyFilters" class="rounded-xl bg-white p-6 shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <div class="grid gap-4 md:grid-cols-3">
            @foreach ($filterFields as $field)
                <label class="grid gap-2 text-sm font-medium text-gray-950 dark:text-white">
                    <span>{{ $field['label'] }}</span>

                    @if ($field['type'] === 'select')
                        <select wire:model="filters.{{ $field['name'] }}" class="rounded-lg border-gray-300 bg-white text-gray-950 shadow-sm dark:border-white/10 dark:bg-white/5 dark:text-white">
                            <option value="">{{ __('reports.filters.choose_student') }}</option>
                            @foreach ($field['options'] as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    @else
                        <input type="{{ $field['type'] }}" wire:model="filters.{{ $field['name'] }}" class="rounded-lg border-gray-300 bg-white text-gray-950 shadow-sm dark:border-white/10 dark:bg-white/5 dark:text-white">
                    @endif

                    @error('filters.'.$field['name'])
                        <span class="text-sm text-danger-600 dark:text-danger-400">{{ $message }}</span>
                    @enderror
                </label>
            @endforeach
        </div>

        <div class="mt-4">
            <x-filament::button type="submit">{{ __('reports.actions.apply_filters') }}</x-filament::button>
        </div>
    </form>

    <div class="overflow-x-auto rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <table class="w-full divide-y divide-gray-200 text-sm dark:divide-white/10">
            <thead class="bg-gray-50 dark:bg-white/5">
                <tr>
                    @foreach ($dataset->columns as $label)
                        <th scope="col" class="px-4 py-3 text-start font-semibold text-gray-950 dark:text-white">{{ $label }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                @forelse ($dataset->rows as $row)
                    <tr>
                        @foreach (array_keys($dataset->columns) as $column)
                            <td class="whitespace-pre-line px-4 py-3 text-gray-700 dark:text-gray-200">{{ $row['cells'][$column] }}</td>
                        @endforeach
                    </tr>
                @empty
                    <tr>
                        <td colspan="{{ max(count($dataset->columns), 1) }}" class="px-4 py-8 text-center text-gray-500 dark:text-gray-400">{{ __('reports.empty') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-filament-panels::page>
