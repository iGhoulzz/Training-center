<?php

declare(strict_types=1);

namespace App\Support;

/**
 * How the centre can be reached, as the public site prints it (P4-T12).
 *
 * THE ONLY PLACE THESE RULES LIVE. The header's top bar, the home page's contact
 * summary and the contact page all show the same email, telephone and map link,
 * and each used to carry its own copy of the trimming, the `tel:` target and the
 * check on the map link. Three copies drift: one trims and another does not, and
 * a number then dials differently from one page to the next. The email-link and
 * phone-link components read this object, and so does the contact page's
 * directions link, so changing a rule here changes it everywhere.
 *
 * The values come from config/public_site.php. Nothing here knows the visitor,
 * so the object is as safe on a sessionless page as the config it reads.
 *
 * - `email`: trimmed. '' when none is set.
 * - `phone`: trimmed, and otherwise as written, separators included. It is the
 *   text a visitor reads.
 * - `telephoneHref`: `tel:` and then the digits only, with a leading plus kept
 *   because it is part of an international number. '' when no phone is set.
 * - `mapUrl`: the trimmed link, but ONLY when it is a plain http(s) URL. A blank
 *   value turns the link off, and a mistyped one (`javascript:...`, a bare path,
 *   another scheme) is never put in an href.
 */
final readonly class PublicContact
{
    private function __construct(
        public string $email,
        public string $phone,
        public string $telephoneHref,
        public string $mapUrl,
    ) {}

    /** The centre's published contact details, read from config/public_site.php. */
    public static function fromConfig(): self
    {
        return self::fromValues(
            (string) config('public_site.contact.email'),
            (string) config('public_site.contact.phone'),
            (string) config('public_site.contact.map_url'),
        );
    }

    /**
     * The same rules over plain values, so they can be tested without a
     * configuration to read. fromConfig() is this and nothing more.
     */
    public static function fromValues(string $email, string $phone, string $mapUrl): self
    {
        $email = trim($email);
        $phone = trim($phone);
        $mapUrl = trim($mapUrl);

        return new self(
            email: $email,
            phone: $phone,
            telephoneHref: $phone === '' ? '' : 'tel:'.(str_starts_with($phone, '+') ? '+' : '').(string) preg_replace('/\D/', '', $phone),
            mapUrl: preg_match('#^https?://#i', $mapUrl) === 1 ? $mapUrl : '',
        );
    }
}
