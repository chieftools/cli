<?php

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
function http(?string $baseUri = null, array $headers = [], int $timeout = 10, array $options = [], ?Closure $stackCallback = null): GuzzleHttp\Client
{
    $stack = GuzzleHttp\HandlerStack::create();

    $stack->push(
        app(App\Support\Guzzle\BearerAuthenticationMiddleware::class),
    );

    if ($stackCallback !== null) {
        $stackCallback($stack);
    }

    return new GuzzleHttp\Client(array_merge($options, [
        'base_uri'        => $baseUri,
        'handler'         => $stack,
        'timeout'         => $timeout,
        'connect_timeout' => $timeout,
        'headers'         => array_merge([
            'Accept'     => 'application/json',
            'User-Agent' => user_agent(),
        ], $headers, $options['headers'] ?? []),
    ]));
}
