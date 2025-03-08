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
        $this->loadConfigurationFile();

        $this->app->singleton(AuthService::class);
        $this->app->singleton(ConfigManager::class);
        $this->app->singleton(DomainChiefService::class);
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
