@use('App\Support\CentreCalendar')
@use('App\Support\PublicRoute')

{{--
    The footer, as the design draws it: the brand and a line about the centre, two
    columns of links, and the copyright line under a hairline.

    THE LINKS. Centre: About, Programmes, Publications, Contact. Services: the
    verifier, the student portal (the same component the header uses, so the portal
    URL is built in one place) and the note that says a student may have no login.
    The design's "Corporate training" line named a service the centre has not
    confirmed, and the design's "Tripoli, Libya" line is left out until the owner
    sends an address.

    THE DESCRIPTION is the draft key already in lang/en/public.php. The design's own
    sentence claims an accreditation the centre has not confirmed, so it is not copied.

    TOUCH TARGETS. Below 1024px a link is a 44px row. From 1024px, where the pointer
    is precise, the rows shrink to the design's own 9px gap.

    The design's two link columns are 180px wide. On a phone each takes its own
    full-width row instead, so the portal note is not squeezed into a strip.

    CONTRAST. Every text colour here is at least 4.5:1 on the navy (WCAG's formula,
    the colour composited over #0d2745). The copyright line is the design's 50%
    cream, which measures 4.54:1, so it passes as drawn and is kept exact; 49% would
    not (4.42:1). The 68% and 72% lines measure 7.17:1 and 7.87:1, the gold headings
    6.39:1 and the cream brand name 13.97:1.
--}}
<footer class="bg-navy text-cream/68">
    <x-public.container class="flex flex-wrap gap-11 pt-13 pb-7.5">
        <div class="min-w-0 flex-[1_1_300px]">
            <x-public.brand variant="footer" />
            <p class="mt-4 max-w-97.5 text-ui leading-[1.75]">{{ __('public.footer.description') }}</p>
        </div>

        <nav aria-labelledby="footer-centre-heading" class="flex-[1_1_100%] sm:flex-[0_1_180px]">
            <h2 id="footer-centre-heading" class="mb-3.25 text-label text-gold uppercase">{{ __('public.footer.centre_heading') }}</h2>

            <ul class="flex flex-col text-ui lg:gap-2.25">
                <li><a href="{{ PublicRoute::url('public.about') }}" class="flex min-h-11 items-center text-cream/72 hover:text-gold-light focus-visible:outline-gold-light lg:min-h-0">{{ __('public.links.about') }}</a></li>
                <li><a href="{{ PublicRoute::url('public.programmes.index') }}" class="flex min-h-11 items-center text-cream/72 hover:text-gold-light focus-visible:outline-gold-light lg:min-h-0">{{ __('public.links.programmes') }}</a></li>
                <li><a href="{{ PublicRoute::url('public.publications.index') }}" class="flex min-h-11 items-center text-cream/72 hover:text-gold-light focus-visible:outline-gold-light lg:min-h-0">{{ __('public.links.publications') }}</a></li>
                <li><a href="{{ PublicRoute::url('public.contact') }}" class="flex min-h-11 items-center text-cream/72 hover:text-gold-light focus-visible:outline-gold-light lg:min-h-0">{{ __('public.links.contact') }}</a></li>
            </ul>
        </nav>

        <nav aria-labelledby="footer-services-heading" class="flex-[1_1_100%] sm:flex-[0_1_180px]">
            <h2 id="footer-services-heading" class="mb-3.25 text-label text-gold uppercase">{{ __('public.footer.services_heading') }}</h2>

            <ul class="flex flex-col text-ui lg:gap-2.25">
                <li><a href="{{ PublicRoute::url('verify.certificates.form') }}" class="flex min-h-11 items-center text-cream/72 hover:text-gold-light focus-visible:outline-gold-light lg:min-h-0">{{ __('public.links.verify_certificate') }}</a></li>
                <li><x-public.portal-link variant="footer" class="flex min-h-11 items-center text-cream/72 hover:text-gold-light focus-visible:outline-gold-light lg:min-h-0" /></li>
                <li class="text-cream/72">{{ __('public.portal.note') }}</li>
            </ul>
        </nav>
    </x-public.container>

    <div class="border-t border-cream/11">
        <x-public.container class="py-4.5 text-caption text-cream/50">
            <p>{{ __('public.footer.copyright', ['year' => CentreCalendar::yearOf(now()), 'name' => config('public_site.brand.name')]) }}</p>
        </x-public.container>
    </div>
</footer>
