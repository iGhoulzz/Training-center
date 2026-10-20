<?php

declare(strict_types=1);

/*
 * Comma-separated operator IPs/CIDRs, only when TLS ends at an upstream.
 * Restrict origin access at the firewall before enabling these addresses.
 * Empty is direct HTTPS at Nginx; wildcard and default-route trust are refused.
 */
$trusted = array_values(array_filter(array_map(trim(...), explode(',', (string) env('TRUSTED_PROXIES', '')))));

foreach ($trusted as $proxy) {
    $parts = explode('/', $proxy);
    $address = $parts[0];
    $maximum = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? 32 : 128;

    if (count($parts) > 2 || ! filter_var($address, FILTER_VALIDATE_IP)
        || (isset($parts[1]) && (! ctype_digit($parts[1]) || (int) $parts[1] < 1 || (int) $parts[1] > $maximum))) {
        throw new InvalidArgumentException('TRUSTED_PROXIES must contain explicit proxy IPs or non-default CIDRs.');
    }
}

return ['trusted' => $trusted];
