<?php

namespace App\Providers;

use App\Services\AuthService;
use App\Services\DomainChiefService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->loadConfigurationFile();

        $this->app->singleton(DomainChiefService::class, function ($app) {
            return new DomainChiefService(
                $app->make(AuthService::class)
            );
        });
    }

    protected function loadConfigurationFile(): void
    {
        $builtInConfig = config('chief');

        $configFile = implode(DIRECTORY_SEPARATOR, [
            $_SERVER['HOME'] ?? $_SERVER['USERPROFILE'] ?? __DIR__,
            '.config/chief',
            'config.php',
        ]);

        if (file_exists($configFile)) {
            $globalConfig = require $configFile;
            config()->set('chief', array_merge($builtInConfig, $globalConfig));
        }
    }
}
