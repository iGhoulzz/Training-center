{{--
    The payment receipt laid out for an 80 mm thermal receipt printer (P4-T24).

    A standalone document, deliberately not the Filament layout and not a Vite
    page: it loads nothing, so it prints the same on a desk PC with no network
    and renders before any asset build exists. Black on white and no
    backgrounds, because a thermal head prints only black. About 72 mm of the
    80 mm roll is the printable width.

    It is rendered by ReceiptViewData, the same builder the A4 PDF uses, so the
    two receipts cannot disagree. Every label is a lang key; every reference and
    amount is dir="ltr" so it reads correctly inside a right-to-left page; CSS
    uses logical properties only.
--}}
<!DOCTYPE html>
<html dir="{{ $direction }}" lang="{{ $locale }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('receipt.print_page_title', ['reference' => $snapshot->payment_reference]) }}</title>
    <style>
        @@page { size: 80mm auto; margin: 0; }

        * { box-sizing: border-box; }

        html, body { background: #fff; color: #000; margin: 0; padding: 0; }

        body {
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Noto Sans Arabic", "Helvetica Neue", Arial, sans-serif;
            font-size: 10.5pt;
            line-height: 1.35;
        }

        .receipt { inline-size: 72mm; margin-inline: auto; padding-block: 4mm; }

        .centre { font-size: 11pt; font-weight: 700; text-align: center; }
        .title { font-size: 12pt; font-weight: 700; margin-block: 2mm; text-align: center; }

        .group { border-block-start: 1px dashed #000; padding-block: 2mm; }

        .row { align-items: baseline; display: flex; flex-wrap: wrap; gap: 0 2mm; justify-content: space-between; padding-block: 0.6mm; }
        .row .value { margin-inline-start: auto; text-align: end; }
        .row.strong { font-weight: 700; }
        .row.stack { display: block; }
        .row.stack .label { display: block; }
        .row.stack .value { display: block; margin-inline-start: 0; text-align: start; }

        .ltr { direction: ltr; unicode-bidi: isolate; }

        .actions { margin-block-start: 4mm; text-align: center; }
        .actions button { background: #fff; border: 1px solid #000; border-radius: 2mm; color: #000; cursor: pointer; font: inherit; padding: 2mm 5mm; }

        @@media print {
            .actions { display: none; }
            .receipt { padding-block: 2mm; }
        }
    </style>
</head>
<body>
    <main class="receipt">
        <div class="centre">{{ config('public_site.brand.name') }}</div>
        <div class="title">{{ __('receipt.title') }}</div>

        <section class="group">
            <div class="row"><span class="label">{{ __('receipt.reference') }}</span><span class="value ltr" dir="ltr">{{ $snapshot->payment_reference }}</span></div>
            <div class="row stack"><span class="label">{{ __('receipt.student') }}</span><span class="value">{!! __('receipt.student_identity', ['code' => '<span class="ltr" dir="ltr">'.e($snapshot->student_code).'</span>', 'name' => e($snapshot->student_name)]) !!}</span></div>
            <div class="row"><span class="label">{{ __('receipt.enrollment') }}</span><span class="value ltr" dir="ltr">{{ $snapshot->enrollment_reference }}</span></div>
            <div class="row"><span class="label">{{ __('receipt.course') }}</span><span class="value ltr" dir="ltr">{{ $snapshot->course_code }}</span></div>
            <div class="row"><span class="label">{{ __('receipt.batch') }}</span><span class="value ltr" dir="ltr">{{ $snapshot->batch_code }}</span></div>
            <div class="row"><span class="label">{{ __('receipt.bill') }}</span><span class="value ltr" dir="ltr">{{ $snapshot->charge_reference }}</span></div>
        </section>

        <section class="group">
            <div class="row"><span class="label">{{ __('receipt.original_price') }}</span><span class="value ltr" dir="ltr">{{ __('receipt.amount_lyd', ['amount' => $snapshot->list_price]) }}</span></div>
            <div class="row"><span class="label">{{ __('receipt.discount') }}</span><span class="value ltr" dir="ltr">{{ __('receipt.discount_percent', ['percent' => $discountPercent]) }}</span></div>
            <div class="row strong"><span class="label">{{ __('receipt.final_charge') }}</span><span class="value ltr" dir="ltr">{{ __('receipt.amount_lyd', ['amount' => $snapshot->final_charge]) }}</span></div>
        </section>

        <section class="group">
            <div class="row strong"><span class="label">{{ __('receipt.amount_paid') }}</span><span class="value ltr" dir="ltr">{{ __('receipt.amount_lyd', ['amount' => $snapshot->amount_paid]) }}</span></div>
            @foreach ($tenders as $tender)
                <div class="row"><span class="label">{{ __('receipt.tender_label', ['method' => $tender['label']]) }}</span><span class="value ltr" dir="ltr">{{ __('receipt.amount_lyd', ['amount' => $tender['amount']]) }}</span></div>
            @endforeach
            <div class="row strong"><span class="label">{{ __('receipt.remaining_balance') }}</span><span class="value ltr" dir="ltr">{{ __('receipt.amount_lyd', ['amount' => $snapshot->remaining_balance]) }}</span></div>
        </section>

        <section class="group">
            <div class="row"><span class="label">{{ __('receipt.payment_date') }}</span><span class="value ltr" dir="ltr">{{ $paymentDate }}</span></div>
            <div class="row"><span class="label">{{ __('receipt.recorded_by') }}</span><span class="value">{{ $snapshot->recorded_by_name }}</span></div>
        </section>

        <div class="actions">
            <button type="button" id="print-again">{{ __('receipt.print_again') }}</button>
        </div>
    </main>

    <script>
        window.addEventListener('load', function () { window.print(); });
        document.getElementById('print-again').addEventListener('click', function () { window.print(); });
    </script>
</body>
</html>
