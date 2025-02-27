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

    /**
     * Validate API key availability
     *
     * @throws \Exception
     */
    private function validateApiKey(): void
    {
        if (!$this->auth->hasApiKey()) {
            throw new \Exception("API key not set. Please use the auth login command to set it.");
        }
    }

    /**
     * Execute API request with automatic token refresh on 401
     *
     * @param string $method HTTP method
     * @param string $endpoint API endpoint
     * @param array $options Request options
     * @param callable $retryCallback Function to call for retry
     * @return array
     * @throws \Exception
     */
    private function executeRequest(string $method, string $endpoint, array $options = [], callable $retryCallback = null): array
    {
        $this->validateApiKey();

        try {
            $response = $this->client->$method($endpoint, $options);
            return json_decode($response->getBody()->getContents(), true);
        } catch (ClientException $e) {
            if ($e->getResponse()->getStatusCode() === 401 && $this->auth->refreshAccessToken()) {
                $this->initializeClient();
                if ($retryCallback) {
                    return $retryCallback();
                }
            }

            throw new \Exception(
                "Failed to {$method} {$endpoint}: " . $e->getMessage(),
                $e->getCode(),
                $e
            );
        } catch (ConnectException $e) {
            throw new \Exception(
                'Failed to connect to the domain service. Please check your internet connection and try again.',
                $e->getCode(),
                $e
            );
        } catch (GuzzleException $e) {
            throw new \Exception(
                "Failed to {$method} {$endpoint}: " . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Process pagination parameters
     *
     * @param array $options Input options
     * @return array Processed query parameters
     * @throws \Exception
     */
    private function processPaginationParams(array $options): array
    {
        $queryParams = [];

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

        return $queryParams;
    }

    /**
     * Process expand parameters
     *
     * @param array $options Input options
     * @return string|null Processed expand parameter
     * @throws \Exception
     */
    private function processExpandParam(array $options): ?string
    {
        if (!isset($options['expand'])) {
            return null;
        }

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

        return !empty($options['expand']) ? implode(',', $options['expand']) : null;
    }

    public function listDomains(array $options = []): array
    {
        $queryParams = $this->processPaginationParams($options);

        // Handle expand parameter
        $expand = $this->processExpandParam($options);
        if ($expand) {
            $queryParams['expand'] = $expand;
        }

        // Handle query parameter
        if (isset($options['query'])) {
            if (!is_string($options['query']) || strlen($options['query']) < 1) {
                throw new \Exception('Query must be a non-empty string');
            }
            $queryParams['query'] = $options['query'];
        }

        return $this->executeRequest('get', 'domains', [
            'query' => $queryParams
        ], function() use ($options) {
            return $this->listDomains($options);
        });
    }

    public function registerOrTransferDomain(array $params): array
    {
        // Validate required parameters
        if (!isset($params['domain'])) {
            throw new \Exception('Domain name is required');
        }

        // Validate domain name length
        if (strlen($params['domain']) < 3 || strlen($params['domain']) > 63) {
            throw new \Exception('Domain name must be between 3 and 63 characters');
        }

        // Validate nameservers and hosted DNS conflicts
        if (isset($params['is_using_hosted_dns']) && isset($params['nameservers'])) {
            throw new \Exception('Cannot provide nameservers when using hosted DNS');
        }

        if (!isset($params['is_using_hosted_dns']) && isset($params['nameservers'])) {
            if (count($params['nameservers']) < 2) {
                throw new \Exception('At least two nameservers are required when not using hosted DNS');
            }
        }

        // Validate WHOIS privacy and contacts conflict
        if (isset($params['is_whois_privacy_enabled']) && isset($params['contacts'])) {
            throw new \Exception('Cannot provide contacts when WHOIS privacy is enabled');
        }

        // Validate DNSSEC keys if provided
        if (isset($params['dnssec_keys'])) {
            $this->validateDnssecKeys($params['dnssec_keys']);
        }

        return $this->executeRequest('post', 'domains', [
            'json' => $params
        ], function() use ($params) {
            return $this->registerOrTransferDomain($params);
        });
    }

    /**
     * Validate DNSSEC keys
     *
     * @param array $keys DNSSEC keys
     * @throws \Exception
     */
    private function validateDnssecKeys(array $keys): void
    {
        foreach ($keys as $key) {
            if (!isset($key['public_key'])) {
                throw new \Exception('Public key is required for DNSSEC keys');
            }

            if (isset($key['algorithm']) && !in_array($key['algorithm'], [1, 2, 3, 5, 6, 7, 8, 10, 12, 13, 14, 15, 16, 17, 23])) {
                throw new \Exception('Invalid DNSSEC algorithm');
            }

            if (isset($key['flags']) && !in_array($key['flags'], [256, 257])) {
                throw new \Exception('Invalid DNSSEC flags. Must be 256 (ZSK) or 257 (KSK)');
            }

            if (isset($key['protocol']) && $key['protocol'] !== 3) {
                throw new \Exception('Invalid DNSSEC protocol. Must be 3');
            }
        }
    }

    public function listContacts(array $options = []): array
    {
        $queryParams = $this->processPaginationParams($options);

        $result = $this->executeRequest('get', 'contacts', [
            'query' => $queryParams
        ], function() use ($options) {
            return $this->listContacts($options);
        });

        if (!isset($result['data']) || !is_array($result['data'])) {
            throw new \Exception('Invalid response format from contacts endpoint');
        }

        return $result;
    }

    public function checkDomainAvailability(string $domainName): array
    {
        $result = $this->executeRequest('get', sprintf('domains/availability/%s', urlencode($domainName)), [],
            function() use ($domainName) {
                return $this->checkDomainAvailability($domainName);
            }
        );

        if (!isset($result['data'])) {
            throw new \Exception('Invalid response format from availability endpoint');
        }

        return $result['data'];
    }

    public function getTldInfo($tld): array
    {
        $result = $this->executeRequest('get', sprintf('tlds/%s', $tld));

        if (!isset($result['data']) || !is_array($result['data'])) {
            throw new \Exception('Invalid response format from contacts endpoint');
        }

        return $result['data'];
    }
}
