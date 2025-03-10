<?php

namespace App\Support\Guzzle;

use Closure;
use Exception;
use App\Services\AuthService;
use Psr\Http\Message\RequestInterface;
use GuzzleHttp\Exception\RequestException;
use function Laravel\Prompts\error;

readonly class BearerAuthenticationMiddleware
{
    public function __construct(
        private AuthService $authService,
    ) {}

    public function __invoke(callable $handler): Closure
    {
        return function (RequestInterface $request, array $options) use ($handler) {
            // Only run this middleware if "bearer" authentication is requested by the caller
            if (!isset($options['auth']) || $options['auth'] !== 'bearer') {
                return $handler($request, $options);
            }

            // @TODO: This might not be the best way to go about this, but it works for now...
            if (!$this->authService->isAuthenticated()) {
                $this->authService->clearTokens();

                error('You must be authenticated to use this command, run `chief auth login` to get started!');

                exit(1);
            }

            $request = $this->setAuthenticationHeaders($request);

            return $handler($request, $options)->then(
                $this->onFulfilled($request, $options, $handler),
            );
        };
    }

    private function onFulfilled(RequestInterface $request, array $options, $handler)
    {
        return function ($response) use ($request, $options, $handler) {
            if ($response && $response->getStatusCode() !== 401) {
                return $response;
            }

            if ($request->hasHeader('X-Guzzle-Auth-Retry')) {
                return $response;
            }

            try {
                // When `false` is returned this usually means there is no refresh token so we can skip refreshing
                if (!$this->authService->refreshAccessToken()) {
                    $this->authService->clearTokens();

                    error('You must be authenticated to use this command, run `chief auth login` to get started!');

                    exit(1);
                }
            } catch (RequestException $e) {
                if ($e->getCode() === 400) {
                    $this->authService->clearTokens();

                    error('Your authentication token is no longer valid, please run `chief auth login` to re-authenticate.');

                    exit(1);
                }

                error('An error occurred while refreshing your access token: ' . $e->getMessage());

                exit(1);
            } catch (Exception $e) {
                error('An unexpected error occurred trying to refresh you authentication token: ' . $e->getMessage());

                exit(1);
            }

            $request = $this
                ->setAuthenticationHeaders($request)
                ->withHeader('X-Guzzle-Auth-Retry', '1');

            return $handler($request, $options);
        };
    }

    private function setAuthenticationHeaders(RequestInterface $request): RequestInterface
    {
        $bearerToken = $this->authService->getBearerToken();

        if ($bearerToken) {
            $request = $request->withHeader('Authorization', "Bearer {$bearerToken}");
        }

        $teamSlug = $this->authService->getTeamSlug();

        if ($teamSlug) {
            $request = $request->withHeader('X-Chief-Team', $teamSlug);
        }

        return $request;
    }
}
