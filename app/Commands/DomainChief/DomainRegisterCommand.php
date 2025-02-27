<?php

namespace App\Commands\DomainChief;

use App\Commands\Command;
use App\Services\DomainChiefService;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;
use function Laravel\Prompts\confirm;
use function Laravel\Prompts\search;
use function Laravel\Prompts\spin;

class DomainRegisterCommand extends Command
{

    private DomainChiefService $domainService;

    protected $signature = 'domain:register {domain?}';
    protected $description = 'Register a new domain name';

    public function __construct(DomainChiefService $domainService)
    {
        parent::__construct();
        $this->domainService = $domainService;
    }

    public function handle()
    {
        try {
            $domain = $this->argument('domain') ??
                text(
                    label: 'Enter the domain name to register or transfer',
                    required: true,
                    validate: fn ($value) => $this->validateDomain($value)
                );

            $isAvailable = spin(
                callback: fn () => $this->domainService->checkDomainAvailability($domain) == 'free',
                message: 'Checking domain availability...'
            );

            $isTransfer = false;
            if (!$isAvailable) {
                if (!confirm(
                    label: "This domain is already registered. Would you like to transfer it?",
                )) {
                    $this->info('Operation cancelled.');
                    return 0;
                }
                $isTransfer = true;
            } else {
                $this->info("Domain {$domain} is available for registration!");
            }

            $params = ['domain' => $domain];

            if ($isTransfer) {
                $params['auth_code'] = text(
                    label: 'Enter the authorization code for the domain transfer',
                    required: true
                );
            }

            // Determine DNS configuration
            $dnsChoice = select(
                label: 'Select DNS configuration',
                options: [
                    'hosted' => 'Use Hosted DNS',
                    'custom' => 'Use Custom Nameservers'
                ]
            );

            $params['is_using_hosted_dns'] = $dnsChoice === 'hosted';

            // If using custom nameservers, collect them
            if ($dnsChoice === 'custom') {
                $params['nameservers'] = $this->collectNameservers();
            }

            // WHOIS Privacy
            $useWhoisPrivacy = confirm(
                label: 'Enable WHOIS Privacy?',
                default: true
            );

            if ($useWhoisPrivacy) {
                $params['is_whois_privacy_enabled'] = true;
            } else {
                // If WHOIS privacy is disabled, ask about contacts
                $useCustomContacts = confirm(
                    label: 'Would you like to specify contacts?',
                    default: false
                );

                if ($useCustomContacts) {
                    $params['contacts'] = $this->collectContacts();
                }
            }

            // DNSSEC
            $useDnssec = confirm(
                label: 'Would you like to configure DNSSEC?',
                default: false
            );

            if ($useDnssec) {
                $params['dnssec_keys'] = $this->collectDnssecKeys();
            }

            // Confirm operation
            $action = $isTransfer ? 'transfer' : 'register';
            if (!confirm(
                label: "Ready to {$action} {$domain}. Proceed?",
                default: true
            )) {
                $this->error("Domain {$action} cancelled.");
                return 1;
            }

            // Register or transfer the domain
            $result = $isTransfer
                ? $this->domainService->transferDomain($params)
                : $this->domainService->registerDomain($params);

            $this->info("Successfully {$action}ed domain: {$domain}");
            $this->table(
                ['Property', 'Value'],
                $this->formatResultForDisplay($result)
            );

            return 0;
        } catch (\Exception $e) {
            $this->error($e->getMessage());
            return 1;
        }
    }

    private function collectContacts(): array
    {
        $contacts = [];
        $contactTypes = ['owner', 'admin', 'tech', 'billing'];

        // Fetch available contacts
        try {
            $availableContacts = $this->domainService->listContacts(['per_page' => 100]);

            // Format contacts for selection
            $contactOptions = [];
            foreach ($availableContacts['data'] as $contact) {
                $label = sprintf(
                    '%s %s%s (%s) - %s',
                    $contact['first_name'],
                    $contact['last_name'],
                    $contact['company_name'] ? ' - ' . $contact['company_name'] : '',
                    $contact['email'],
                    $contact['handle']
                );

                if ($contact['is_default']) {
                    $label .= ' [Default]';
                }

                $contactOptions[$contact['handle']] = $label;
            }

            if (empty($contactOptions)) {
                $this->warn('No contacts found. Please create contacts first.');
                if (!confirm('Continue without contacts?', default: false)) {
                    throw new \Exception('Registration cancelled - no contacts available');
                }
                return [];
            }

            // Add a "Skip" option
            $contactOptions['skip'] = 'Skip this contact type';

            foreach ($contactTypes as $type) {
                $this->info("\nSelecting {$type} contact:");

                $selectedContact = search(
                    label: "Search and select {$type} contact",
                    options: $contactOptions,
                    placeholder: 'Start typing to search contacts...'
                );

                if ($selectedContact !== 'skip') {
                    $contacts[$type] = $selectedContact;
                }
            }

            return $contacts;

        } catch (\Exception $e) {
            $this->warn('Failed to fetch contacts: ' . $e->getMessage());
            if (!confirm('Continue without contacts?', default: false)) {
                throw new \Exception('Registration cancelled - could not fetch contacts');
            }
            return [];
        }
    }

    private function validateDomain(string $domain): ?string
    {
        if (strlen($domain) < 3 || strlen($domain) > 63) {
            return 'Domain name must be between 3 and 63 characters';
        }

        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9-]*[a-zA-Z0-9]?\.[a-zA-Z]{2,}$/', $domain)) {
            return 'Invalid domain name format';
        }

        return null;
    }

    private function collectNameservers(): array
    {
        $nameservers = [];
        $minNameservers = 2;

        $this->info("You need to provide at least {$minNameservers} nameservers.");

        for ($i = 1; $i <= $minNameservers; $i++) {
            $nameserver = [
                'hostname' => text(
                    label: "Enter nameserver #{$i} hostname",
                    required: true,
                    validate: fn ($value) => $this->validateHostname($value)
                )
            ];

            if (confirm(
                label: "Would you like to add IPv4 for nameserver #{$i}?",
                default: false
            )) {
                $nameserver['ipv4'] = text(
                    label: "Enter IPv4 address",
                    validate: fn ($value) => $this->validateIPv4($value)
                );
            }

            if (confirm(
                label: "Would you like to add IPv6 for nameserver #{$i}?",
                default: false
            )) {
                $nameserver['ipv6'] = text(
                    label: "Enter IPv6 address",
                    validate: fn ($value) => $this->validateIPv6($value)
                );
            }

            $nameservers[] = $nameserver;
        }

        // Option to add more nameservers
        while (count($nameservers) < 13 && confirm(
                label: 'Would you like to add another nameserver?',
                default: false
            )) {
            $i = count($nameservers) + 1;
            $nameserver = [
                'hostname' => text(
                    label: "Enter nameserver #{$i} hostname",
                    required: true,
                    validate: fn ($value) => $this->validateHostname($value)
                )
            ];

            // Similar IPv4/IPv6 collection as above
            if (confirm(
                label: "Would you like to add IPv4 for nameserver #{$i}?",
                default: false
            )) {
                $nameserver['ipv4'] = text(
                    label: "Enter IPv4 address",
                    validate: fn ($value) => $this->validateIPv4($value)
                );
            }

            if (confirm(
                label: "Would you like to add IPv6 for nameserver #{$i}?",
                default: false
            )) {
                $nameserver['ipv6'] = text(
                    label: "Enter IPv6 address",
                    validate: fn ($value) => $this->validateIPv6($value)
                );
            }

            $nameservers[] = $nameserver;
        }

        return $nameservers;
    }

    private function collectDnssecKeys(): array
    {
        $keys = [];
        $algorithms = [
            1 => 'RSA/MD5',
            2 => 'Diffie-Hellman',
            3 => 'DSA/SHA1',
            5 => 'RSA/SHA-1',
            6 => 'DSA-NSEC3-SHA1',
            7 => 'RSASHA1-NSEC3-SHA1',
            8 => 'RSA/SHA-256',
            10 => 'RSA/SHA-512',
            12 => 'GOST R 34.10-2001',
            13 => 'ECDSA Curve P-256 with SHA-256',
            14 => 'ECDSA Curve P-384 with SHA-384',
            15 => 'Ed25519',
            16 => 'Ed448',
            17 => 'INDIRECT',
            23 => 'ECC-GOST'
        ];

        do {
            $key = [
                'public_key' => text(
                    label: 'Enter the public key',
                    required: true
                ),
                'algorithm' => select(
                    label: 'Select the DNSSEC algorithm',
                    options: $algorithms
                ),
                'flags' => select(
                    label: 'Select the key flags',
                    options: [
                        256 => 'ZSK (Zone Signing Key)',
                        257 => 'KSK (Key Signing Key)'
                    ]
                ),
                'protocol' => 3
            ];

            $keys[] = $key;

        } while (confirm(
            label: 'Would you like to add another DNSSEC key?',
            default: false
        ));

        return $keys;
    }

    private function validateHostname(string $hostname): ?string
    {
        if (!preg_match('/^(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$/', $hostname)) {
            return 'Invalid hostname format';
        }
        return null;
    }

    private function validateIPv4(string $ip): ?string
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return 'Invalid IPv4 address';
        }
        return null;
    }

    private function validateIPv6(string $ip): ?string
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return 'Invalid IPv6 address';
        }
        return null;
    }

    private function formatResultForDisplay(array $result): array
    {
        $display = [];
        foreach ($result as $key => $value) {
            if (is_array($value)) {
                $value = json_encode($value, JSON_PRETTY_PRINT);
            } elseif (is_bool($value)) {
                $value = $value ? 'Yes' : 'No';
            }
            $display[] = [$key, $value];
        }
        return $display;
    }
}
