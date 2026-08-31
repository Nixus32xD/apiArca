<?php

namespace App\Providers;

use App\Services\Fiscal\Contracts\WsaaClient;
use App\Services\Fiscal\Contracts\Wsfev1Client;
use App\Services\Fiscal\SequenceAwareWsfev1Client;
use App\Services\Fiscal\WSAAService;
use App\Services\Fiscal\WSFEv1Service;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(WsaaClient::class, WSAAService::class);
        $this->app->bind(Wsfev1Client::class, function ($app): Wsfev1Client {
            return new SequenceAwareWsfev1Client($app->make(WSFEv1Service::class));
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
