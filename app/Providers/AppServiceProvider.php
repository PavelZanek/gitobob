<?php

namespace App\Providers;

use App\Preferences;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\View as Page;

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
        View::composer(['workspace', 'repository', 'branches', 'conflicts', 'stashes', 'stash-switch', 'commit', 'push', 'cloud', 'cloud-pull', 'settings'],
            static fn (Page $page): Page => $page->with('preferences', Preferences::read()));
    }
}
