<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies;

/**
 * Trusts only the reverse-proxy CIDRs from config('security.ip.trusted_proxies'),
 * read per request. Empty config means no proxy is trusted.
 */
class TrustConfiguredProxies extends TrustProxies
{
    /** @return array<int, string>|null */
    protected function proxies()
    {
        $list = array_values(array_filter(array_map('trim', explode(',', (string) config('security.ip.trusted_proxies')))));

        return $list === [] ? null : $list;
    }
}
