<?php

namespace App\Support;

/**
 * Cloudflare in front of the site (Layer 1, owner 2026-11-09).
 *
 * Behind Cloudflare every request reaches the server from a Cloudflare address, so the visitor's own address only
 * arrives in X-Forwarded-For. The app trusts that header ONLY from these published Cloudflare ranges
 * (https://www.cloudflare.com/ips-v4 and /ips-v6, copied 2026-11-09). A request from anywhere else keeps its own
 * address and any X-Forwarded-For it sends is ignored, so nobody can fake their address to dodge sign-in / OTP limits.
 * Without this, all customers would share a few Cloudflare addresses and one person's failed sign-ins would lock out
 * everyone.
 */
final class Cloudflare
{
    public const IP_RANGES = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18',
        '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17',
        '162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32',
        '2a06:98c0::/29', '2c0f:f248::/32',
    ];
}
