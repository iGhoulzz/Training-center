<!DOCTYPE html>
<html dir="{{ $direction }}" lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <style>
        body { color: #111827; direction: {{ $direction }}; font-family: sans-serif; font-size: 11pt; }
        h1 { margin-block-end: 16px; text-align: start; }
        table { border-collapse: collapse; inline-size: 100%; margin-block-end: 16px; }
        th, td { border: 1px solid #d1d5db; padding-block: 6px; padding-inline: 8px; text-align: start; }
        th { background: #f3f4f6; font-weight: 700; }
        .ltr { direction: ltr; }
        .amount { direction: ltr; text-align: end; }
    </style>
</head>
<body>
    <h1>{{ __('receipt.title') }}</h1>
    <table>
        <tr><th>{{ __('receipt.reference') }}</th><td class="ltr">{{ $snapshot->payment_reference }}</td></tr>
        <tr><th>{{ __('receipt.student') }}</th><td>{{ __('receipt.student_identity', ['code' => $snapshot->student_code, 'name' => $snapshot->student_name]) }}</td></tr>
        <tr><th>{{ __('receipt.enrollment') }}</th><td class="ltr">{{ $snapshot->enrollment_reference }}</td></tr>
        <tr><th>{{ __('receipt.course') }}</th><td class="ltr">{{ $snapshot->course_code }}</td></tr>
        <tr><th>{{ __('receipt.batch') }}</th><td class="ltr">{{ $snapshot->batch_code }}</td></tr>
        <tr><th>{{ __('receipt.bill') }}</th><td class="ltr">{{ $snapshot->charge_reference }}</td></tr>
        <tr><th>{{ __('receipt.original_price') }}</th><td class="amount">{{ __('receipt.amount_lyd', ['amount' => $snapshot->list_price]) }}</td></tr>
        <tr><th>{{ __('receipt.discount') }}</th><td class="amount">{{ $snapshot->discount_percentage ?? '0.00' }}</td></tr>
        <tr><th>{{ __('receipt.final_charge') }}</th><td class="amount">{{ __('receipt.amount_lyd', ['amount' => $snapshot->final_charge]) }}</td></tr>
        <tr><th>{{ __('receipt.amount_paid') }}</th><td class="amount">{{ __('receipt.amount_lyd', ['amount' => $snapshot->amount_paid]) }}</td></tr>
        @foreach ($tenders as $tender)
            <tr><th>{{ __('receipt.tender_label', ['method' => $tender['label']]) }}</th><td class="amount">{{ __('receipt.amount_lyd', ['amount' => $tender['amount']]) }}</td></tr>
        @endforeach
        <tr><th>{{ __('receipt.remaining_balance') }}</th><td class="amount">{{ __('receipt.amount_lyd', ['amount' => $snapshot->remaining_balance]) }}</td></tr>
        <tr><th>{{ __('receipt.payment_date') }}</th><td class="amount">{{ $paymentDate }}</td></tr>
        <tr><th>{{ __('receipt.recorded_by') }}</th><td>{{ $snapshot->recorded_by_name }}</td></tr>
    </table>
</body>
</html>
