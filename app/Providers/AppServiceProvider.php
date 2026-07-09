<?php

namespace App\Providers;

use App\Models\Area;
use App\Models\FunctionalLocation;
use App\Models\SubArea;
use App\Observers\AreaObserver;
use App\Observers\FunctionalLocationObserver;
use App\Observers\SubAreaObserver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        FunctionalLocation::observe(FunctionalLocationObserver::class);
        Area::observe(AreaObserver::class);
        SubArea::observe(SubAreaObserver::class);
    }
}
