<?php

namespace App\Providers;

use App\Preferences;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\View;
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
        Event::listen(RouteMatched::class, function (): void {
            $preferences = Preferences::read();
            $this->app->setLocale($preferences->language);
            View::share('preferences', $preferences);
        });
    }
}
