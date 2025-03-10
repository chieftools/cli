<?php

namespace App\Services;

use Exception;
use RuntimeException;
use Illuminate\Support\Arr;

readonly class AuthService
{
    public function __construct(
        private ConfigManager $config,
    ) {}

    private function getOpenIDConfig(string $key): mixed
    {
        static $memoizedConfig = null;

        if ($memoizedConfig === null) {
            $memoizedConfig = $this->makeRequest('GET', config('chief.endpoints.openid'));
        }

        return Arr::get($memoizedConfig, $key);
    }

    public function initiateDeviceAuth(): array
    {
        return $this->makeRequest('POST', $this->getOpenIDConfig('device_authorization_endpoint'), [
            'json' => [
                'client_id' => config('chief.client_id'),
                'scope'     => config('chief.scopes'),
            ],
        ]);
    }

    public function pollForToken(array $authData): ?array
    {
        $start = time();

        $requestData = [
            'json' => [
                'client_id'   => config('chief.client_id'),
                'device_code' => $authData['device_code'],
                'grant_type'  => 'urn:ietf:params:oauth:grant-type:device_code',
            ],
        ];

        while (time() - $start < $authData['expires_in']) {
            try {
                $response = $this->makeRequest('POST', $this->getOpenIDConfig('token_endpoint'), $requestData);

                if (!isset($response['error'])) {
                    return [
                        'access_token'  => $response['access_token'],
                        'refresh_token' => $response['refresh_token'],
                        'expires_in'    => $response['expires_in'],
                    ];
                }

                if ($response['error'] !== 'authorization_pending') {
                    return null;
                }

                sleep($authData['interval']);
            } catch (Exception) {
                // @TODO: Should we try to handle something here?
                sleep($authData['interval']);
            }
        }

        return null;
    }

    public function completeAuthentication(array $tokenData): array
    {
        $user = $this->makeRequest('GET', $this->getOpenIDConfig('userinfo_endpoint'), [
            'headers' => [
                'Authorization' => "Bearer {$tokenData['access_token']}",
            ],
        ]);

        $this->updateTokens(
            $tokenData['access_token'],
            $tokenData['refresh_token'] ?? null,
        );

        $team = Arr::first($user['teams']);

        $this->updateTeamInfo(
            $team['slug'] ?? null,
            $team['name'] ?? null,
        );

        return $user;
    }

    public function refreshAccessToken(): bool
    {
        $refreshToken = $this->config->get('refresh_token');

        if (empty($refreshToken)) {
            return false;
        }

        $response = $this->makeRequest('POST', $this->getOpenIDConfig('token_endpoint'), [
            'json' => [
                'client_id'     => config('chief.client_id'),
                'refresh_token' => $refreshToken,
                'grant_type'    => 'refresh_token',
                'scope'         => config('chief.scopes'),
            ],
        ]);

        // Validate response contains required fields
        if (!isset($response['access_token'])) {
            throw new RuntimeException('Invalid token response');
        }

        $this->updateTokens(
            $response['access_token'],
            $response['refresh_token'] ?? null,
        );

        $userInfo = $this->getUserInfo();

        $team = Arr::first($userInfo['teams']);

        $this->updateTeamInfo(
            $team['slug'] ?? null,
            $team['name'] ?? null,
        );

        return true;
    }

    public function revokeTokens(): void
    {
        if (!$this->config->has('refresh_token') && !$this->config->has('access_token')) {
            return;
        }

        http()->post($this->getOpenIDConfig('revocation_endpoint'), [
            'json' => [
                'client_id' => config('chief.client_id'),
                // If we are revoking the refresh token we don't need to also revoke the access token since they are connected
                'token'     => $this->config->get('refresh_token') ?? $this->config->get('access_token'),
            ],
        ]);
    }

    public function getUserInfo(): array
    {
        return $this->makeRequest('GET', $this->getOpenIDConfig('userinfo_endpoint'), [
            'auth' => 'bearer',
        ]);
    }

    public function getTokenInfo(): array
    {
        return $this->makeRequest('POST', $this->getOpenIDConfig('introspection_endpoint'), [
            'json' => [
                'client_id' => config('chief.client_id'),
                'token'     => $this->getBearerToken(),
            ],
        ]);
    }

    public function clearAuthData(): void
    {
        $this->config->reset();
    }

    public function getBearerToken(): ?string
    {
        return $this->config->get('access_token');
    }

    public function isAuthenticated(): bool
    {
        return $this->config->has('access_token');
    }

    public function getTeamSlug(): ?string
    {
        return $this->config->get('team_slug');
    }

    public function hasTeamSlug(): bool
    {
        return $this->config->has('team_slug');
    }

    public function clearTokens(): void
    {
        $this->config->setMultiple([
            'access_token'  => null,
            'refresh_token' => null,
        ]);
    }

    public function updateTokens(string $accessToken, ?string $refreshToken): void
    {
        $this->config->setMultiple([
            'access_token'  => $accessToken,
            'refresh_token' => $refreshToken,
        ]);
    }

    public function updateTeamInfo(?string $teamSlug, ?string $teamName): void
    {
        $this->config->setMultiple([
            'team_slug' => $teamSlug,
            'team_name' => $teamName,
        ]);
    }

    private function makeRequest(string $method, string $url, array $options = []): array
    {
        $response = http()->request($method, $url, $options);

        return json_decode($response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }
}
