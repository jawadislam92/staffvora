<?php

namespace App\Providers;

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
        // Ensure avatars directory exists
        $avatarsPath = storage_path('app/public/avatars');
        if (! file_exists($avatarsPath)) {
            mkdir($avatarsPath, 0755, true);
        }
    }
}
