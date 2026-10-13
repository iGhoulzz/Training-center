{{--
    The panel's brand: the centre's seal as a 28px circle beside the short name, as the
    prototype's sidebar draws its mark and name (with its placeholder "TC" square and
    "Tripoli Training Centre" replaced by the real ones). The full English name does not
    fit the 258px sidebar, so the lockup is the short name only.

    Both come from config/public_site.php, never from app.name, so the brand is changed in
    one place. Filament renders this view twice, once in the sidebar's header (below
    1024px) and once in the topbar (from 1024px), inside its own `.fi-logo` box, whose
    height is the panel's brandLogoHeight (28px). theme.css gives the classes below
    their measurements.

    The seal is decoration, so its alt text is empty: the name beside it carries the
    meaning, and a link around the lockup is announced as that name.
--}}
<span class="tc-brand">
    <img
        src="{{ asset(config('public_site.brand.logo')) }}"
        alt=""
        width="28"
        height="28"
        class="tc-brand-mark"
    >
    <span class="tc-brand-name">{{ config('public_site.brand.short_name') }}</span>
</span>
