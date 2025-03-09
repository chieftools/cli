<?php

namespace App\Providers;

use App\Services\AuthService;
use App\Services\ConfigManager;
use App\Services\DomainChiefService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            ConfigManager::class,
            static fn () => new ConfigManager($_SERVER['HOME'] . '/.config/chief'),
        );

        $this->app->singleton(AuthService::class);
        $this->app->singleton(DomainChiefService::class);
    }
}
