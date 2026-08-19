<!DOCTYPE html>
<html dir="rtl" lang="en">
<head>
    <meta charset="utf-8">
    <style>
        body { color: #111827; direction: rtl; font-family: sans-serif; font-size: 11pt; }
        h1 { margin-block-end: 16px; text-align: start; }
        table { border-collapse: collapse; inline-size: 100%; margin-block-end: 16px; }
        th, td { border: 1px solid #d1d5db; padding-block: 6px; padding-inline: 8px; text-align: start; }
        th { background: #f3f4f6; font-weight: 700; }
        .amount { direction: ltr; text-align: end; }
    </style>
</head>
<body>
    <h1>{{ __('receipt.title') }}</h1>
    <table>
        <tr><th>{{ __('receipt.reference') }}</th><td>{{ $payment->reference }}</td></tr>
        <tr><th>{{ __('receipt.student') }}</th><td>{{ __('receipt.student_identity', ['code' => $context['student_code'], 'name' => $context['student_name']]) }}</td></tr>
        <tr><th>{{ __('receipt.enrollment') }}</th><td>{{ $context['enrollment_reference'] }}</td></tr>
        <tr><th>{{ __('receipt.course') }}</th><td>{{ $context['course_code'] }}</td></tr>
        <tr><th>{{ __('receipt.batch') }}</th><td>{{ $context['batch_code'] }}</td></tr>
        <tr><th>{{ __('receipt.bill') }}</th><td>{{ $charge->reference }}</td></tr>
        <tr><th>{{ __('receipt.original_price') }}</th><td class="amount">{{ __('receipt.amount_lyd', ['amount' => $charge->list_price]) }}</td></tr>
        <tr><th>{{ __('receipt.discount') }}</th><td class="amount">{{ $charge->discount_percentage ?? '0.00' }}</td></tr>
        <tr><th>{{ __('receipt.final_charge') }}</th><td class="amount">{{ __('receipt.amount_lyd', ['amount' => $charge->amount]) }}</td></tr>
        <tr><th>{{ __('receipt.amount_paid') }}</th><td class="amount">{{ __('receipt.amount_lyd', ['amount' => $amountPaid]) }}</td></tr>
        @foreach ($tenders as $tender)
            <tr><th>{{ __('receipt.tender_label', ['method' => $tender['label']]) }}</th><td class="amount">{{ __('receipt.amount_lyd', ['amount' => $tender['amount']]) }}</td></tr>
        @endforeach
        <tr><th>{{ __('receipt.remaining_balance') }}</th><td class="amount">{{ __('receipt.amount_lyd', ['amount' => $remainingBalance]) }}</td></tr>
        <tr><th>{{ __('receipt.payment_date') }}</th><td class="amount">{{ $paymentDate }}</td></tr>
        <tr><th>{{ __('receipt.recorded_by') }}</th><td>{{ $payment->recordedBy->name }}</td></tr>
    </table>
</body>
</html>
