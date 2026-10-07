<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Header keamanan untuk semua respons: browser tidak menebak tipe isi (MIME sniffing),
 * jadi file unduhan dan JSON selalu diperlakukan sesuai Content-Type dari server.
 */
class SetSecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
