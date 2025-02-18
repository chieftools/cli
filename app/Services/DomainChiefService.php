<?php

namespace App\Services;

use GuzzleHttp\Client;

class DomainChiefService
{
    private ?string $apiKey;
    private string $baseUrl;
    private Client $httpClient;
    private ConfigManager $configManager;

    /**
     * Valid expand options for the API
     */
    private const VALID_EXPAND_VALUES = ['tld', 'contacts'];

    public function __construct(string $baseUrl = 'https://domain.chief.app/api/v1')
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->configManager = new ConfigManager();
        $this->initializeClient();
    }

    private function initializeClient()
    {
        if (!$this->configManager->has('api_key')) {
            return false;
        }

        $this->httpClient = new Client([
            'base_uri' => $this->baseUrl . '/',
            'headers' => [
                'Authorization' => 'Bearer ' . $this->configManager->get('api_key'),
                'Accept' => 'application/json',
            ]
        ]);
    }

    /**
     * List all domains for the authenticated team
     *
     * @param array $options Array of query parameters
     *                      - expand: array of strings (allowed: 'tld', 'contacts')
     *                      - page: int >= 1
     *                      - per_page: int (1-100)
     *                      - query: string (domain filter)
     * @return array
     * @throws \Exception
     */
    public function listDomains(array $options = []): array
    {
        if (!$this->configManager->has('api_key')) {
            throw new \Exception("API key not set. Please use the AuthService 'login' command to set it.");
        }

        $queryParams = [];

        // Handle expand parameter
        if (isset($options['expand'])) {
            if (!is_array($options['expand'])) {
                throw new \Exception('Expand parameter must be an array');
            }

            // Validate expand values
            $invalidValues = array_diff($options['expand'], self::VALID_EXPAND_VALUES);
            if (!empty($invalidValues)) {
                throw new \Exception(sprintf(
                    'Invalid expand values: %s. Allowed values are: %s',
                    implode(', ', $invalidValues),
                    implode(', ', self::VALID_EXPAND_VALUES)
                ));
            }

            // Add expand values as separate array items
            if (!empty($options['expand'])) {
                $queryParams['expand'] = $options['expand'];
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
            $response = $this->httpClient->get('domains', [
                'query' => $queryParams
            ]);

            return json_decode($response->getBody()->getContents(), true);
        } catch (\GuzzleHttp\Exception\GuzzleException $e) {
            throw new \Exception(
                'Failed to list domains: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }
}
