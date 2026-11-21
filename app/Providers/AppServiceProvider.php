<?php

namespace App\Providers;

use App\Services\GowaWhatsappService;
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
        View::composer('layouts.app', function ($view) {
            // Hanya membaca cache (tanpa HTTP) agar perpindahan menu tetap instan
            $view->with('waState', app(GowaWhatsappService::class)->cachedStatus());
        });
    }
}
