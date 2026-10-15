<?php

namespace App\Providers;

use App\Models\CarbonEntry;
use App\Models\User;
use App\Observers\CarbonEntryObserver;
use App\Observers\UserObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        CarbonEntry::observe(CarbonEntryObserver::class);
        User::observe(UserObserver::class);

        // Maks 5 percobaan login per menit untuk kombinasi email + IP
        RateLimiter::for('login', function (Request $request) {
            $email = $request->input('email');

            return Limit::perMinute(5)
                ->by((is_string($email) ? Str::lower($email) : '').'|'.$request->ip())
                ->response(fn (Request $request, array $headers) => response()->json([
                    'message' => 'Terlalu banyak percobaan login. Coba lagi nanti.',
                ], 429, $headers));
        });

        $tooManyResets = fn (Request $request, array $headers) => response()->json([
            'message' => 'Terlalu banyak permintaan reset password. Coba lagi nanti.',
        ], 429, $headers);

        // Lupa password: maks 5/menit per IP, dan maks 3/jam per alamat email agar kotak masuk
        // seseorang tidak bisa dibanjiri email reset (respons 429 sama untuk email apa pun)
        RateLimiter::for('forgot-password', function (Request $request) use ($tooManyResets) {
            $email = $request->input('email');

            return [
                Limit::perMinute(5)->by('forgot-ip|'.$request->ip())->response($tooManyResets),
                Limit::perHour(3)->by('forgot-email|'.(is_string($email) ? Str::lower(trim($email)) : ''))->response($tooManyResets),
            ];
        });

        // Simpan password baru dari link reset: maks 5/menit per IP
        RateLimiter::for('password-reset', function (Request $request) use ($tooManyResets) {
            return Limit::perMinute(5)->by('reset-ip|'.$request->ip())->response($tooManyResets);
        });

        // Batas umum endpoint ber-auth, dihitung per user (rute tamu punya limiter sendiri)
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute((int) config('app.api_rate_limit'))
                ->by($request->user()?->id ?: $request->ip())
                ->response(fn (Request $request, array $headers) => response()->json([
                    'message' => 'Terlalu banyak permintaan. Coba lagi sebentar lagi.',
                ], 429, $headers));
        });

        // Id di URL harus angka yang muat di int: "abc" atau 20 digit menjadi 404, bukan TypeError (500)
        foreach (['id', 'userId', 'projectId', 'jobId'] as $parameter) {
            Route::pattern($parameter, '[0-9]{1,18}');
        }
    }
}
