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
        $this->app->singleton(GatewayResolver::class, function ($app) {
            return new GatewayResolver([
                $app->make(Gateway1Adapter::class),
                $app->make(Gateway2Adapter::class),
            ]);
        });
    }

    public function boot(): void
    {
        //
    }
}
