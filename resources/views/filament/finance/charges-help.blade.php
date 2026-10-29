{{--
    The "How to read this page" body on the Charges list (P4-T37). Three plain
    groups: the cards, a row, the buttons. Every sentence is a lang key; the
    button names and badge words are the labels the page itself shows.
--}}
<div class="grid min-w-0 gap-5 text-[12.5px] leading-relaxed text-gray-300 lg:grid-cols-3">
    <section class="flex min-w-0 flex-col gap-2">
        <h3 class="text-[12px] font-semibold text-gray-50">{{ __('charges.help_cards_title') }}</h3>
        <p>{{ __('charges.help_cards_total', ['unpaid' => __('charges.unpaid'), 'part_paid' => __('charges.part_paid')]) }}</p>
        <p>{{ __('charges.help_cards_sum') }}</p>
        <p>{{ __('charges.help_cards_written_off') }}</p>
    </section>

    <section class="flex min-w-0 flex-col gap-2">
        <h3 class="text-[12px] font-semibold text-gray-50">{{ __('charges.help_rows_title') }}</h3>
        <ul class="flex flex-col gap-1.5">
            <li>{{ __('charges.help_row_billed') }}</li>
            <li>{{ __('charges.help_row_paid') }}</li>
            <li>{{ __('charges.help_row_owed') }}</li>
            <li>{{ __('charges.help_row_badge', [
                'unpaid' => __('charges.unpaid'),
                'part_paid' => __('charges.part_paid'),
                'settled' => __('charges.settled'),
                'written_off' => __('charges.written_off'),
            ]) }}</li>
        </ul>
        <p>{{ __('charges.help_reversal') }}</p>
    </section>

    <section class="flex min-w-0 flex-col gap-2">
        <h3 class="text-[12px] font-semibold text-gray-50">{{ __('charges.help_buttons_title') }}</h3>
        <dl class="flex flex-col gap-1.5">
            <div>
                <dt class="font-semibold text-success-400">{{ __('charges.collect_payment') }}</dt>
                <dd>{{ __('charges.help_collect_payment') }}</dd>
            </div>
            <div>
                <dt class="font-semibold text-gray-50">{{ __('charges.adjust') }}</dt>
                <dd>{{ __('charges.help_adjust') }}</dd>
            </div>
            <div>
                <dt class="font-semibold text-danger-400">{{ __('charges.write_off') }}</dt>
                <dd>{{ __('charges.help_write_off') }}</dd>
            </div>
        </dl>
    </section>
</div>
