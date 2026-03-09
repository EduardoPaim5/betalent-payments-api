<?php

namespace App\Providers;

use App\Services\Payments\Gateways\Gateway1Adapter;
use App\Services\Payments\Gateways\Gateway2Adapter;
use App\Services\Payments\Gateways\GatewayResolver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(GatewayResolver::class, function () {
            return new GatewayResolver([
                new Gateway1Adapter(),
                new Gateway2Adapter(),
            ]);
        });
    }

    public function boot(): void
    {
        //
    }
}
