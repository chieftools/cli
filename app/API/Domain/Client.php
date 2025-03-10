<?php

namespace App\API\Domain;

use RuntimeException;
use App\Services\AuthService;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\ConnectException;

class Client
{
    private const VALID_EXPAND_VALUES = ['tld', 'contacts'];

    private HttpClient $client;

    public function __construct(
        private readonly AuthService $auth,
    ) {
        $this->client = http(
            baseUri: rtrim(config('chief.endpoints.domain'), '/') . '/api/v1/',
            options: [
                'auth' => 'bearer',
            ],
        );
    }

    private function executeRequest(string $method, string $endpoint, array $options = [], ?callable $retryCallback = null): array
    {
        if (!$this->auth->isAuthenticated()) {
            throw new RuntimeException("Use 'chief auth login' to authenticate first.");
        }

        try {
            $response = $this->client->$method($endpoint, $options);

            return json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);
        } catch (ConnectException $e) {
            throw new RuntimeException(
                'Failed to connect. Please check your internet connection and try again.',
                $e->getCode(),
                $e,
            );
        } catch (GuzzleException $e) {
            throw new RuntimeException(
                "Failed to {$method} {$endpoint}: {$e->getMessage()}",
                $e->getCode(),
                $e,
            );
        }
    }

    private function processPaginationParams(array $options): array
    {
        $queryParams = [];

        if (isset($options['page'])) {
            if (!is_int($options['page']) || $options['page'] < 1) {
                throw new RuntimeException('Page must be an integer >= 1');
            }

            $queryParams['page'] = $options['page'];
        }

        if (isset($options['per_page'])) {
            if (!is_int($options['per_page']) || $options['per_page'] < 1 || $options['per_page'] > 100) {
                throw new RuntimeException('Per page must be an integer between 1 and 100');
            }

            $queryParams['per_page'] = $options['per_page'];
        }

        return $queryParams;
    }

    private function processExpandParam(array $options): ?string
    {
        if (!isset($options['expand'])) {
            return null;
        }

        if (!is_array($options['expand'])) {
            throw new RuntimeException('Expand parameter must be an array');
        }

        $invalidValues = array_diff($options['expand'], self::VALID_EXPAND_VALUES);
        if (!empty($invalidValues)) {
            throw new RuntimeException(sprintf(
                'Invalid expand values: %s. Allowed values are: %s',
                implode(', ', $invalidValues),
                implode(', ', self::VALID_EXPAND_VALUES),
            ));
        }

        return !empty($options['expand']) ? implode(',', $options['expand']) : null;
    }

    private function validateDnssecKeys(array $keys): void
    {
        foreach ($keys as $key) {
            if (!isset($key['public_key'])) {
                throw new RuntimeException('Public key is required for DNSSEC keys');
            }

            if (isset($key['algorithm']) && !in_array($key['algorithm'], [1, 2, 3, 5, 6, 7, 8, 10, 12, 13, 14, 15, 16, 17, 23])) {
                throw new RuntimeException('Invalid DNSSEC algorithm');
            }

            if (isset($key['flags']) && !in_array($key['flags'], [256, 257])) {
                throw new RuntimeException('Invalid DNSSEC flags. Must be 256 (ZSK) or 257 (KSK)');
            }

            if (isset($key['protocol']) && $key['protocol'] !== 3) {
                throw new RuntimeException('Invalid DNSSEC protocol. Must be 3');
            }
        }
    }

    public function getTldInfo($tld): array
    {
        $result = $this->executeRequest('get', sprintf('tlds/%s', $tld));

        if (!isset($result['data']) || !is_array($result['data'])) {
            throw new RuntimeException('Invalid response format from contacts endpoint');
        }

        return $result['data'];
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
                throw new RuntimeException('Query must be a non-empty string');
            }
            $queryParams['query'] = $options['query'];
        }

        return $this->executeRequest('get', 'domains', [
            'query' => $queryParams,
        ], function () use ($options) {
            return $this->listDomains($options);
        });
    }

    public function listContacts(array $options = []): array
    {
        $queryParams = $this->processPaginationParams($options);

        $result = $this->executeRequest('get', 'contacts', [
            'query' => $queryParams,
        ], function () use ($options) {
            return $this->listContacts($options);
        });

        if (!isset($result['data']) || !is_array($result['data'])) {
            throw new RuntimeException('Invalid response format from contacts endpoint');
        }

        return $result;
    }

    public function checkDomainAvailability(string $domainName): array
    {
        $result = $this->executeRequest('get', sprintf('domains/availability/%s', urlencode($domainName)), [],
            function () use ($domainName) {
                return $this->checkDomainAvailability($domainName);
            },
        );

        if (!isset($result['data'])) {
            throw new RuntimeException('Invalid response format from availability endpoint');
        }

        return $result['data'];
    }

    public function registerOrTransferDomain(array $params): array
    {
        // Validate required parameters
        if (!isset($params['domain'])) {
            throw new RuntimeException('Domain name is required');
        }

        // Validate domain name length
        if (strlen($params['domain']) < 3 || strlen($params['domain']) > 63) {
            throw new RuntimeException('Domain name must be between 3 and 63 characters');
        }

        // Validate nameservers and hosted DNS conflicts
        if (isset($params['is_using_hosted_dns']) && isset($params['nameservers'])) {
            throw new RuntimeException('Cannot provide nameservers when using hosted DNS');
        }

        if (!isset($params['is_using_hosted_dns']) && isset($params['nameservers'])) {
            if (count($params['nameservers']) < 2) {
                throw new RuntimeException('At least two nameservers are required when not using hosted DNS');
            }
        }

        // Validate WHOIS privacy and contacts conflict
        if (isset($params['is_whois_privacy_enabled']) && isset($params['contacts'])) {
            throw new RuntimeException('Cannot provide contacts when WHOIS privacy is enabled');
        }

        // Validate DNSSEC keys if provided
        if (isset($params['dnssec_keys'])) {
            $this->validateDnssecKeys($params['dnssec_keys']);
        }

        return $this->executeRequest('post', 'domains', [
            'json' => $params,
        ], function () use ($params) {
            return $this->registerOrTransferDomain($params);
        });
    }
}
