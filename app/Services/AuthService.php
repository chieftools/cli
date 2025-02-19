<?php

namespace App\Services;

use AllowDynamicProperties;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Arr;

#[AllowDynamicProperties] class AuthService
{
    private const CLIENT_ID = 'clichief';
    private const REQUIRED_SCOPES = 'profile email teams offline_access domainchief';
    private const AUTH_CONFIG_URL = 'https://account.chief.app/.well-known/openid-configuration';
    private const DEFAULT_TIMEOUT = 30;

    private Client $client;
    private string $baseUrl;
    private ?string $apiKey;
    private ?string $teamSlug;
    private ConfigManager $configManager;

    public function __construct(ConfigManager $configManager = null)
    {
        $this->configManager = $configManager ?? new ConfigManager();
        $this->baseUrl = config('chief.auth_endpoint');
        $this->apiKey = $this->configManager->get('api_key');
        $this->teamSlug = $this->configManager->get('team_slug');
        $this->initializeClient();
    }

    private function initializeClient(): void
    {
        $this->client = new Client([
            'base_uri' => $this->baseUrl,
            'timeout' => self::DEFAULT_TIMEOUT,
            'headers' => $this->getHeaders(),
        ]);
    }

    private function getHeaders(): array
    {
        $headers = [
            'Accept' => 'application/json',
        ];

        if ($this->apiKey) {
            $headers['Authorization'] = 'Bearer ' . $this->apiKey;
        }

        if ($this->teamSlug) {
            $headers['X-Chief-Team'] = $this->teamSlug;
        }

        return $headers;
    }

    private function makeRequest(string $method, string $url, array $options = []): array
    {
        $client = new Client(['timeout' => self::DEFAULT_TIMEOUT]);

        try {
            $response = $client->request($method, $url, $options);
            return json_decode($response->getBody(), true);
        } catch (GuzzleException $e) {
            throw new \Exception("Request failed: " . $e->getMessage());
        }
    }

    public function initiateDeviceAuth(): array
    {
        $config = $this->makeRequest('GET', self::AUTH_CONFIG_URL);
        $authorization = $this->makeRequest('POST', $config['device_authorization_endpoint'], [
            'json' => [
                'client_id' => self::CLIENT_ID,
                'scope' => self::REQUIRED_SCOPES,
            ]
        ]);

        return [
            'verification_uri' => $authorization['verification_uri_complete'],
            'device_code' => $authorization['device_code'],
            'expires_in' => $authorization['expires_in'],
            'interval' => $authorization['interval'],
            'token_endpoint' => $config['token_endpoint'],
            'userinfo_endpoint' => $config['userinfo_endpoint'],
        ];
    }

    public function pollForToken(array $authData): ?array
    {
        $start = time();
        $tokenEndpoint = $authData['token_endpoint'];
        $requestData = [
            'json' => [
                'client_id' => self::CLIENT_ID,
                'device_code' => $authData['device_code'],
                'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
            ]
        ];

        while (time() - $start < $authData['expires_in']) {
            try {
                $response = $this->makeRequest('POST', $tokenEndpoint, $requestData);

                if (!isset($response['error'])) {
                    return [
                        'access_token' => $response['access_token'],
                        'refresh_token' => $response['refresh_token'],
                        'expires_in' => $response['expires_in'],
                    ];
                }

                if ($response['error'] !== 'authorization_pending') {
                    return null;
                }

                sleep($authData['interval']);
            } catch (\Exception $e) {
                sleep($authData['interval']);
            }
        }

        return null;
    }

    public function completeAuthentication(array $tokenData, string $userinfoEndpoint): array
    {
        $user = $this->makeRequest('GET', $userinfoEndpoint, [
            'headers' => ['Authorization' => 'Bearer ' . $tokenData['access_token']]
        ]);

        $this->configManager->updateAuthData($tokenData['access_token'], $tokenData['refresh_token'], Arr::first($user['teams'])['slug']);
        $this->apiKey = $tokenData['access_token'];
        $this->teamSlug = Arr::first($user['teams'])['slug'];
        $this->initializeClient();

        return $user;
    }

    public function refreshAccessToken(): bool
    {
        $refreshToken = $this->configManager->get('refresh_token');
        if (empty($refreshToken)) {
            return false;
        }

        try {
            $config = $this->makeRequest('GET', self::AUTH_CONFIG_URL);

            // Validate token endpoint exists
            if (empty($config['token_endpoint'])) {
                throw new \Exception('Invalid auth configuration');
            }

            $response = $this->makeRequest('POST', $config['token_endpoint'], [
                'json' => [
                    'client_id' => self::CLIENT_ID,
                    'refresh_token' => $refreshToken,
                    'grant_type' => 'refresh_token',
                    'scope' => self::REQUIRED_SCOPES,
                ]
            ]);

            // Validate response contains required fields
            if (!isset($response['access_token'])) {
                throw new \Exception('Invalid token response');
            }

            // Get user info to ensure we have valid team data
            $userInfo = $this->makeRequest('GET', $config['userinfo_endpoint'], [
                'headers' => ['Authorization' => 'Bearer ' . $response['access_token']]
            ]);

            if (empty($userInfo['teams'])) {
                throw new \Exception('No teams available in user info');
            }

            // Update with new tokens and team data
            $this->configManager->updateAuthData(
                $response['access_token'],
                $response['refresh_token'] ?? $refreshToken,
                Arr::first($userInfo['teams'])['slug']
            );

            $this->apiKey = $response['access_token'];
            $this->teamSlug = Arr::first($userInfo['teams'])['slug'];
            $this->initializeClient();

            return true;
        } catch (\Exception $e) {
            // Log the error instead of silent fail
            error_log('Refresh token error: ' . $e->getMessage());
            return false;
        }
    }
    public function clearApiKey(): void
    {
        $this->configManager->remove('api_key');
        $this->apiKey = null;
        $this->teamSlug = null;
        $this->initializeClient();
    }

    public function request(string $method, string $endpoint, array $data = []): array
    {
        if (!$this->apiKey) {
            throw new \Exception("API key not set. Use 'login' command to set it.");
        }

        try {
            $normalizedEndpoint = '/api' . ($endpoint[0] === '/' ? $endpoint : '/' . $endpoint);
            $response = $this->client->request($method, $normalizedEndpoint, ['json' => $data]);
            return json_decode($response->getBody(), true);
        } catch (GuzzleException $e) {
            // Check specifically for 401 status
            if ($e->getResponse() && $e->getResponse()->getStatusCode() === 401) {
                // Try to refresh the token
                if ($this->refreshAccessToken()) {
                    // Retry the original request with new token
                    return $this->request($method, $endpoint, $data);
                }
            }
            throw new \Exception("API request failed: " . $e->getMessage());
        }
    }

    public function getTeam(): ?string
    {
        return $this->teamSlug;
    }

    public function getUserInfo(): array
    {
        return $this->request('GET', '/oauth/userinfo');
    }

    public function hasApiKey(): bool
    {
        return !empty($this->apiKey);
    }

    public function hasTeam(): bool
    {
        return !empty($this->teamSlug);
    }

    public function getApiKey(): ?string
    {
        return $this->apiKey;
    }
}
