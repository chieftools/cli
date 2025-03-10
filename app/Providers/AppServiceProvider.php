<?php

namespace App\Providers;

use App\API\Domain\Client;
use App\Services\AuthService;
use App\Services\ConfigManager;
use Illuminate\Support\ServiceProvider;
use App\Support\Guzzle\BearerAuthenticationMiddleware;
use LaravelZero\Framework\Components\Updater\Strategy\GithubReleasesStrategy;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            ConfigManager::class,
            static fn () => new ConfigManager($_SERVER['HOME'] . '/.config/chief'),
        );

        $this->app->singleton(AuthService::class);
        $this->app->singleton(Client::class);
        $this->app->singleton(BearerAuthenticationMiddleware::class);

        $this->app->afterResolving(GithubReleasesStrategy::class, function (GithubReleasesStrategy $strategy) {
            $strategy->setPharName('chief.phar');
        });
    }
}
