@use('App\Support\CentreCalendar')
@use('App\Support\PublicRoute')

<footer class="bg-navy-900 text-cream-100">
    <div class="mx-auto grid max-w-6xl gap-8 px-4 py-10 md:grid-cols-[2fr_1fr]">
        <div>
            <p class="flex items-center gap-3">
                <span
                    aria-hidden="true"
                    class="grid size-10 place-items-center rounded-full bg-navy-800 font-serif text-lg text-gold-400 ring-1 ring-gold-500"
                >{{ __('public.brand.mark') }}</span>
                <span class="font-serif text-lg font-semibold">{{ config('app.name') }}</span>
            </p>
            <p class="mt-3 max-w-md text-sm text-cream-200">{{ __('public.footer.description') }}</p>
        </div>

        <nav aria-labelledby="footer-services-heading">
            <h2 id="footer-services-heading" class="text-sm font-semibold uppercase tracking-wider text-gold-400">{{ __('public.footer.services_heading') }}</h2>

            <ul class="mt-3 flex flex-col gap-2 text-sm">
                <li><a href="{{ PublicRoute::url('verify.certificates.form') }}" class="hover:underline focus-visible:outline-gold-400">{{ __('public.links.verify_certificate') }}</a></li>
            </ul>
        </nav>
    </div>

    <div class="border-t border-cream-100/15">
        <p class="mx-auto max-w-6xl px-4 py-4 text-sm text-cream-200">{{ __('public.footer.copyright', ['year' => CentreCalendar::yearOf(now()), 'name' => config('app.name')]) }}</p>
    </div>
</footer>
