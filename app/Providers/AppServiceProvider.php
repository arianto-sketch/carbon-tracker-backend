<?php

namespace App\Providers;

use App\Models\CarbonEntry;
use App\Observers\CarbonEntryObserver;
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

        // Maks 5 percobaan login per menit untuk kombinasi email + IP
        RateLimiter::for('login', function (Request $request) {
            $email = $request->input('email');

            return Limit::perMinute(5)
                ->by((is_string($email) ? Str::lower($email) : '').'|'.$request->ip())
                ->response(fn (Request $request, array $headers) => response()->json([
                    'message' => 'Terlalu banyak percobaan login. Coba lagi nanti.',
                ], 429, $headers));
        });

        // Batas umum endpoint ber-auth, dihitung per user (rute tamu hanya login, yang punya limiter sendiri)
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute((int) config('app.api_rate_limit'))
                ->by($request->user()?->id ?: $request->ip())
                ->response(fn (Request $request, array $headers) => response()->json([
                    'message' => 'Terlalu banyak permintaan. Coba lagi sebentar lagi.',
                ], 429, $headers));
        });

        // Id di URL harus angka: "abc" menjadi 404, bukan TypeError (500) di parameter int controller
        foreach (['id', 'userId', 'projectId', 'jobId'] as $parameter) {
            Route::pattern($parameter, '[0-9]+');
        }
    }
}
