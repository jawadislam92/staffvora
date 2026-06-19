<?php

namespace App\Providers;

use App\Models\User;
use App\Tenancy\CurrentOrganization;
use Illuminate\Auth\Events\Authenticated;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One tenant context shared across the request/command.
        $this->app->singleton(CurrentOrganization::class);

        // Billing is manual for now; swap this binding for a gateway adapter later.
        $this->app->bind(\App\Billing\PaymentProvider::class, \App\Billing\ManualBankTransfer::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Bind the active tenant whenever a user is authenticated — guard
        // agnostic, so it covers web sessions, Sanctum API tokens, and
        // actingAs() in tests without depending on middleware ordering.
        Event::listen(Authenticated::class, function (Authenticated $event) {
            if ($event->user instanceof User && $event->user->organization_id) {
                app(CurrentOrganization::class)->set($event->user->organization_id);
            }
        });

        // Ensure avatars directory exists
        $avatarsPath = storage_path('app/public/avatars');
        if (! file_exists($avatarsPath)) {
            mkdir($avatarsPath, 0755, true);
        }
    }
}
