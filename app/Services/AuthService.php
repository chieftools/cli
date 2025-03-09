<?php

namespace App\Services;

use Exception;
use RuntimeException;
use GuzzleHttp\Client;
use Illuminate\Support\Arr;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\BadResponseException;

class AuthService
{
    private Client $client;

    public function __construct(
        private readonly ConfigManager $config,
    ) {
        $this->client = http(rtrim(config('chief.endpoints.auth'), '/') . '/api/');
    }

    public function initiateDeviceAuth(): array
    {
        $config = $this->makeRequest('GET', config('chief.endpoints.openid'));

        $authorization = $this->makeRequest('POST', $config['device_authorization_endpoint'], [
            'json' => [
                'client_id' => config('chief.client_id'),
                'scope'     => config('chief.scopes'),
            ],
        ]);

        return [
            'verification_uri'  => $authorization['verification_uri_complete'],
            'device_code'       => $authorization['device_code'],
            'expires_in'        => $authorization['expires_in'],
            'interval'          => $authorization['interval'],
            'token_endpoint'    => $config['token_endpoint'],
            'userinfo_endpoint' => $config['userinfo_endpoint'],
        ];
    }

    public function pollForToken(array $authData): ?array
    {
        $start         = time();
        $tokenEndpoint = $authData['token_endpoint'];
        $requestData   = [
            'json' => [
                'client_id'   => config('chief.client_id'),
                'device_code' => $authData['device_code'],
                'grant_type'  => 'urn:ietf:params:oauth:grant-type:device_code',
            ],
        ];

        while (time() - $start < $authData['expires_in']) {
            try {
                $response = $this->makeRequest('POST', $tokenEndpoint, $requestData);

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
            } catch (Exception $e) {
                sleep($authData['interval']);
            }
        }

        return null;
    }

    public function completeAuthentication(array $tokenData, string $userInfoEndpoint): array
    {
        $user = $this->makeRequest('GET', $userInfoEndpoint, [
            'headers' => ['Authorization' => 'Bearer ' . $tokenData['access_token']],
        ]);

        $team = Arr::first($user['teams']);

        $this->updateAuthData(
            $tokenData['access_token'],
            $tokenData['refresh_token'] ?? null,
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

        $config = $this->makeRequest('GET', config('chief.endpoints.openid'));

        // Validate token endpoint exists
        if (empty($config['token_endpoint'])) {
            throw new RuntimeException('Invalid auth configuration');
        }

        $response = $this->makeRequest('POST', $config['token_endpoint'], [
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

        // Get user info to ensure we have valid team data
        $userInfo = $this->makeRequest('GET', $config['userinfo_endpoint'], [
            'headers' => ['Authorization' => 'Bearer ' . $response['access_token']],
        ]);

        if (empty($userInfo['teams'])) {
            throw new RuntimeException('No teams available in user info');
        }

        $team = Arr::first($userInfo['teams']);

        $this->updateAuthData(
            $response['access_token'],
            $response['refresh_token'] ?? null,
            $team['slug'] ?? null,
            $team['name'] ?? null,
        );

        return true;
    }

    public function getUserInfo(): array
    {
        return $this->request('GET', '/oauth/userinfo');
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

    public function updateAuthData(string $accessToken, ?string $refreshToken, ?string $teamSlug, ?string $teamName): void
    {
        $this->config->setMultiple([
            'access_token'  => $accessToken,
            'refresh_token' => $refreshToken,
            'team_slug'     => $teamSlug,
            'team_name'     => $teamName,
        ]);
    }

    private function request(string $method, string $endpoint, array $data = []): array
    {
        if (!$this->isAuthenticated()) {
            throw new RuntimeException("Use 'chief auth login' to authenticate first.");
        }

        try {
            $normalizedEndpoint = '/api' . ($endpoint[0] === '/' ? $endpoint : '/' . $endpoint);

            $response = $this->client->request($method, $normalizedEndpoint, [
                'json'    => $data,
                'headers' => $this->getHeaders(),
            ]);

            return json_decode($response->getBody(), true);
        } catch (GuzzleException $e) {
            // Check specifically for 401 status
            if ($e instanceof BadResponseException && $e->getResponse()->getStatusCode() === 401) {
                // Try to refresh the token
                if ($this->refreshAccessToken()) {
                    // Retry the original request with new token
                    return $this->request($method, $endpoint, $data);
                }
            }

            throw new RuntimeException('API request failed: ' . $e->getMessage());
        }
    }

    private function makeRequest(string $method, string $url, array $options = []): array
    {
        $client = http(headers: $this->getHeaders());

        try {
            $response = $client->request($method, $url, $options);

            return json_decode($response->getBody(), true);
        } catch (GuzzleException $e) {
            throw new RuntimeException('Request failed: ' . $e->getMessage());
        }
    }

    private function getHeaders(): array
    {
        $headers = [
            'Accept' => 'application/json',
        ];

        if ($this->isAuthenticated()) {
            $headers['Authorization'] = "Bearer {$this->getBearerToken()}";
        }

        if ($this->hasTeamSlug()) {
            $headers['X-Chief-Team'] = $this->getTeamSlug();
        }

        return $headers;
    }
}
