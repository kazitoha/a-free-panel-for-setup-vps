<?php

namespace App\Providers;

use App\Services\UpdateService;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\View as ViewInstance;

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
        // The update banner is a plain file read — guests and CLI rendering
        // skip it entirely (no shared status variable at all).
        View::composer('layouts.app', function (ViewInstance $view): void {
            $view->with(
                'panelUpdate',
                auth()->check() ? app(UpdateService::class)->status() : null,
            );
        });
    }
}
