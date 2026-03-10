<?php

namespace App\Providers;

use App\Models\Client;
use App\Models\Gateway;
use App\Models\Product;
use App\Models\Refund;
use App\Models\Transaction;
use App\Models\User;
use App\Policies\ClientPolicy;
use App\Policies\GatewayPolicy;
use App\Policies\ProductPolicy;
use App\Policies\RefundPolicy;
use App\Policies\TransactionPolicy;
use App\Policies\UserPolicy;
use App\Services\Payments\Gateways\Gateway1Adapter;
use App\Services\Payments\Gateways\Gateway2Adapter;
use App\Services\Payments\Gateways\GatewayResolver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
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
        Gate::policy(Client::class, ClientPolicy::class);
        Gate::policy(Gateway::class, GatewayPolicy::class);
        Gate::policy(Product::class, ProductPolicy::class);
        Gate::policy(Refund::class, RefundPolicy::class);
        Gate::policy(Transaction::class, TransactionPolicy::class);
        Gate::policy(User::class, UserPolicy::class);

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
