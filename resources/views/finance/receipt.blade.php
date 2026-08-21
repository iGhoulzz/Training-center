<!DOCTYPE html>
<html dir="{{ $direction }}" lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <style>
        body { color: #111827; direction: {{ $direction }}; font-family: sans-serif; font-size: 11pt; }
        h1 { margin: 0; }
        table { border-collapse: collapse; margin: 16px 0; }
        th, td { border: 1px solid #d1d5db; padding: 6px 8px; }
        th { background: #f3f4f6; font-weight: 700; }
        .ltr { direction: ltr; }
        .amount { direction: ltr; }
    </style>
</head>
<body>
    <h1>{{ __('receipt.title') }}</h1>
    <table width="100%">
        <tr><th>{{ __('receipt.reference') }}</th><td class="ltr" dir="ltr">{{ $snapshot->payment_reference }}</td></tr>
        <tr><th>{{ __('receipt.student') }}</th><td>{!! __('receipt.student_identity', ['code' => '<span dir="ltr">'.e($snapshot->student_code).'</span>', 'name' => e($snapshot->student_name)]) !!}</td></tr>
        <tr><th>{{ __('receipt.enrollment') }}</th><td class="ltr" dir="ltr">{{ $snapshot->enrollment_reference }}</td></tr>
        <tr><th>{{ __('receipt.course') }}</th><td class="ltr" dir="ltr">{{ $snapshot->course_code }}</td></tr>
        <tr><th>{{ __('receipt.batch') }}</th><td class="ltr" dir="ltr">{{ $snapshot->batch_code }}</td></tr>
        <tr><th>{{ __('receipt.bill') }}</th><td class="ltr" dir="ltr">{{ $snapshot->charge_reference }}</td></tr>
        <tr><th>{{ __('receipt.original_price') }}</th><td class="amount" dir="ltr">{{ __('receipt.amount_lyd', ['amount' => $snapshot->list_price]) }}</td></tr>
        <tr><th>{{ __('receipt.discount') }}</th><td class="amount" dir="ltr">{{ $snapshot->discount_percentage ?? '0.00' }}</td></tr>
        <tr><th>{{ __('receipt.final_charge') }}</th><td class="amount" dir="ltr">{{ __('receipt.amount_lyd', ['amount' => $snapshot->final_charge]) }}</td></tr>
        <tr><th>{{ __('receipt.amount_paid') }}</th><td class="amount" dir="ltr">{{ __('receipt.amount_lyd', ['amount' => $snapshot->amount_paid]) }}</td></tr>
        @foreach ($tenders as $tender)
            <tr><th>{{ __('receipt.tender_label', ['method' => $tender['label']]) }}</th><td class="amount" dir="ltr">{{ __('receipt.amount_lyd', ['amount' => $tender['amount']]) }}</td></tr>
        @endforeach
        <tr><th>{{ __('receipt.remaining_balance') }}</th><td class="amount" dir="ltr">{{ __('receipt.amount_lyd', ['amount' => $snapshot->remaining_balance]) }}</td></tr>
        <tr><th>{{ __('receipt.payment_date') }}</th><td class="amount" dir="ltr">{{ $paymentDate }}</td></tr>
        <tr><th>{{ __('receipt.recorded_by') }}</th><td>{{ $snapshot->recorded_by_name }}</td></tr>
    </table>
</body>
</html>
