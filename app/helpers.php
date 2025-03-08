<?php

/**
 * Get the authentication service singleton.
 */
function authService(): App\Services\AuthService
{
    return app(App\Services\AuthService::class);
}

/**
 * Get the user agent for the application.
 */
function user_agent(): string
{
    return sprintf(
        'ChiefToolsCLI/%s (+https://aka.chief.app/cli)',
        config('app.version'),
    );
}

/**
 * Get a HTTP client to use with sane timeouts and defaults.
 */
function http(?string $baseUri = null, array $headers = [], int $timeout = 10, array $options = []): GuzzleHttp\Client
{
    return new GuzzleHttp\Client(array_merge($options, [
        'base_uri'        => $baseUri,
        'timeout'         => $timeout,
        'connect_timeout' => $timeout,
        'headers'         => array_merge([
            'User-Agent' => user_agent(),
        ], $headers, $options['headers'] ?? []),
    ]));
}
