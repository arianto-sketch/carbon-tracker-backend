<?php

namespace App\Providers;

use App\Models\CarbonEntry;
use App\Observers\CarbonEntryObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        CarbonEntry::observe(CarbonEntryObserver::class);
    }
}
