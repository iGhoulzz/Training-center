<!doctype html>
<html lang="{{ $locale }}" dir="{{ $direction }}">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: sans-serif; font-size: 10pt; color: #111827; }
        h1 { font-size: 18pt; margin: 0; margin-block-end: 8pt; }
        .filters { margin-block-end: 16pt; }
        .filter { margin-block-end: 3pt; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #d1d5db; padding: 6pt; vertical-align: top; }
        th { background: #f3f4f6; font-weight: bold; }
        .empty { padding: 18pt; text-align: center; color: #6b7280; }
    </style>
</head>
<body>
    <h1>{{ $snapshot->title }}</h1>
    <div class="filters">
        @foreach ($snapshot->filters as $label => $value)
            <div class="filter"><strong>{{ $label }}:</strong> {{ $value }}</div>
        @endforeach
    </div>
    <table>
        <thead>
            <tr>
                @foreach ($snapshot->dataset->columns as $label)
                    <th>{{ $label }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($snapshot->dataset->rows as $row)
                <tr>
                    @foreach (array_keys($snapshot->dataset->columns) as $column)
                        <td>{{ $row['cells'][$column] }}</td>
                    @endforeach
                </tr>
            @empty
                <tr>
                    <td class="empty" colspan="{{ max(count($snapshot->dataset->columns), 1) }}">{{ __('reports.empty') }}</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
