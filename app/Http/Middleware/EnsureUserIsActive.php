<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->is_active) {
            // Cabut semua token supaya akun nonaktif tidak bisa dipakai lagi
            $user->tokens()->delete();

            return response()->json(['message' => 'Akun Anda tidak aktif. Hubungi administrator.'], 401);
        }

        return $next($request);
    }
}
