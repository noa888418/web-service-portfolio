<?php

namespace App\Providers;

use App\Auth\PrivateDatabaseSessionHandler;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

final class AuthServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app['session']->extend('private-database', fn ($app) => new PrivateDatabaseSessionHandler(
            $app['db']->connection('pgsql'), 'sessions', 480, $app
        ));
        Sanctum::getAccessTokenFromRequestUsing(fn () => null);
    }
}
