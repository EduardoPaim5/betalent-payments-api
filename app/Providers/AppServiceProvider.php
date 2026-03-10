<?php

namespace App\Providers;

use App\Services\Payments\Gateways\Gateway1Adapter;
use App\Services\Payments\Gateways\Gateway2Adapter;
use App\Services\Payments\Gateways\GatewayResolver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

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
        RateLimiter::for('login', function (Request $request): Limit {
            $email = Str::lower((string) $request->input('email', 'guest'));

            return Limit::perMinute(5)->by($email.'|'.$request->ip());
        });

        RateLimiter::for('purchases', function (Request $request): Limit {
            $fingerprint = sha1((string) $request->input('client.email', 'guest'));

            return Limit::perMinute(20)->by($request->ip().'|'.$fingerprint);
        });
    }
}
