<?php

namespace App\Providers;

use App\Models\CarbonEntry;
use App\Observers\CarbonEntryObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
            return Limit::perMinute(5)
                ->by(Str::lower((string) $request->input('email')).'|'.$request->ip())
                ->response(fn () => response()->json([
                    'message' => 'Terlalu banyak percobaan login. Coba lagi dalam 1 menit.',
                ], 429));
        });
    }
}
