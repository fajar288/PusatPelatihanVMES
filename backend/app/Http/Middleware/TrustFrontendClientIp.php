<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Frontend Next.js memanggil API dari server-nya sendiri (server action / server component), jadi tanpa ini semua
 * permintaan tampak berasal dari satu IP dan pembatas laju per IP berlaku untuk seluruh pengunjung sekaligus.
 * Frontend meneruskan IP pengunjung di `X-Client-IP` bersama `X-Proxy-Secret`; IP itu hanya dipercaya bila secret
 * cocok dengan API_PROXY_SECRET (konstanta waktu), sehingga klien lain tidak bisa memalsukan IP-nya.
 */
class TrustFrontendClientIp
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('services.frontend.proxy_secret');
        $given = (string) $request->headers->get('X-Proxy-Secret', '');
        $ip = trim((string) $request->headers->get('X-Client-IP', ''));

        if ($secret !== '' && $given !== '' && hash_equals($secret, $given) && filter_var($ip, FILTER_VALIDATE_IP)) {
            $request->server->set('REMOTE_ADDR', $ip);
        }
        // secret tidak boleh ikut tercatat di log/permintaan lanjutan
        $request->headers->remove('X-Proxy-Secret');

        return $next($request);
    }
}
