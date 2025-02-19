<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ConnectException;

class DomainChiefService
{
    private AuthService $auth;
    private Client $client;
    private string $baseUrl;

    /**
     * Valid expand options for the API
     */
    private const VALID_EXPAND_VALUES = ['tld', 'contacts'];

    public function __construct(AuthService $auth)
    {
        $this->auth = $auth;
        $this->baseUrl = config('chief.domain_endpoint', 'https://domain.chief.app');
        $this->initializeClient();
    }

    private function initializeClient(): void
    {
        $this->client = new Client([
            'base_uri' => rtrim($this->baseUrl, '/') . '/api/v1/',
            'timeout' => 30,
            'headers' => $this->getHeaders(),
        ]);
    }

    private function getHeaders(): array
    {
        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];

        if ($this->auth->hasApiKey()) {
            $headers['Authorization'] = 'Bearer ' . $this->auth->getApiKey();
        }

        if ($this->auth->getTeam()) {
            $headers['X-Chief-Team'] = $this->auth->getTeam();
        }

        return $headers;
    }

    public function listDomains(array $options = []): array
    {
        if (!$this->auth->hasApiKey()) {
            throw new \Exception("API key not set. Please use the auth login command to set it.");
        }

        $queryParams = [];

        // Handle expand parameter
        if (isset($options['expand'])) {
            if (!is_array($options['expand'])) {
                throw new \Exception('Expand parameter must be an array');
            }

            $invalidValues = array_diff($options['expand'], self::VALID_EXPAND_VALUES);
            if (!empty($invalidValues)) {
                throw new \Exception(sprintf(
                    'Invalid expand values: %s. Allowed values are: %s',
                    implode(', ', $invalidValues),
                    implode(', ', self::VALID_EXPAND_VALUES)
                ));
            }

            if (!empty($options['expand'])) {
                $queryParams['expand'] = implode(',', $options['expand']);
            }
        }

        // Handle pagination
        if (isset($options['page'])) {
            if (!is_int($options['page']) || $options['page'] < 1) {
                throw new \Exception('Page must be an integer >= 1');
            }
            $queryParams['page'] = $options['page'];
        }

        if (isset($options['per_page'])) {
            if (!is_int($options['per_page']) || $options['per_page'] < 1 || $options['per_page'] > 100) {
                throw new \Exception('Per page must be an integer between 1 and 100');
            }
            $queryParams['per_page'] = $options['per_page'];
        }

        // Handle query parameter
        if (isset($options['query'])) {
            if (!is_string($options['query']) || strlen($options['query']) < 1) {
                throw new \Exception('Query must be a non-empty string');
            }
            $queryParams['query'] = $options['query'];
        }

        try {
            $response = $this->client->get('domains', [
                'query' => $queryParams
            ]);

            $result = json_decode($response->getBody()->getContents(), true);

            return $result;
        } catch (ClientException $e) {
            // Handle HTTP errors (4xx, 5xx) that have responses
            if ($e->getResponse()->getStatusCode() === 401) {
                if ($this->auth->refreshAccessToken()) {
                    // Reinitialize client with new token and retry
                    $this->initializeClient();
                    return $this->listDomains($options);
                }
            }

            throw new \Exception(
                'Failed to list domains: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        } catch (ConnectException $e) {
            // Handle connection errors (DNS, refused, timeout, etc.)
            throw new \Exception(
                'Failed to connect to the domain service. Please check your internet connection and try again.',
                $e->getCode(),
                $e
            );
        } catch (GuzzleException $e) {
            // Handle any other Guzzle errors
            throw new \Exception(
                'Failed to list domains: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }
}
