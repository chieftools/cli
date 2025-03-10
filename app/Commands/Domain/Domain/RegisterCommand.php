<?php

namespace App\Commands\Domain\Domain;

use Exception;
use RuntimeException;
use App\Commands\Command;
use App\API\Domain\Client;
use function Laravel\Prompts\form;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\text;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\pause;
use function Laravel\Prompts\search;
use function Laravel\Prompts\select;
use function Laravel\Prompts\confirm;

class RegisterCommand extends Command
{
    protected $signature   = 'domain:register {domain? : Domain name to register or transfer}';
    protected $description = 'Register a new domain name';

    public function handle(Client $domainClient): int
    {
        try {
            $responses = form()
                // Step 1: Get domain name
                ->text(
                    label: 'Enter the domain name to register or transfer',
                    default: $this->argument('domain') ?? '',
                    required: true,
                    validate: fn ($value) => $this->validateDomain($value),
                    name: 'domain',
                )
                // Step 2: Check domain availability and load TLD info
                ->add(function ($responses) use ($domainClient) {
                    $domain  = $responses['domain'];
                    $tld     = pathinfo($domain, PATHINFO_EXTENSION);
                    $tldInfo = $domainClient->getTldInfo($tld);

                    $availabilityCheck = spin(
                        callback: fn () => $domainClient->checkDomainAvailability($domain),
                        message: 'Checking domain availability...',
                    );

                    $isAvailable       = $availabilityCheck['availability'] === 'free';
                    $registrationPrice = !empty($tldInfo['registration_price']) ? number_format($tldInfo['registration_price'] / 100, 2) : 'Unknown';
                    $transferPrice     = !empty($tldInfo['transfer_price']) ? number_format($tldInfo['transfer_price'] / 100, 2) : 'Unknown';
                    $renewalPrice      = !empty($tldInfo['renewal_price']) ? number_format($tldInfo['renewal_price'] / 100, 2) : 'Unknown';

                    $supportsWhoisPrivacy = $tldInfo['supports_whois_privacy'] ?? false;
                    $supportsDnssec       = $tldInfo['supports_dnssec'] ?? false;

                    return [
                        'tld'                  => $tld,
                        'tldInfo'              => $tldInfo,
                        'isAvailable'          => $isAvailable,
                        'registrationPrice'    => $registrationPrice,
                        'transferPrice'        => $transferPrice,
                        'renewalPrice'         => $renewalPrice,
                        'supportsWhoisPrivacy' => $supportsWhoisPrivacy,
                        'supportsDnssec'       => $supportsDnssec,
                    ];
                }, name: 'domain_info')
                // Step 3: Handle registration vs transfer path
                ->add(function ($responses) {
                    $domain            = $responses['domain'];
                    $isAvailable       = $responses['domain_info']['isAvailable'];
                    $transferPrice     = $responses['domain_info']['transferPrice'];
                    $registrationPrice = $responses['domain_info']['registrationPrice'];
                    $renewalPrice      = $responses['domain_info']['renewalPrice'];

                    if (!$isAvailable) {
                        $this->warn("Domain {$domain} is already registered.");

                        return pause("Press ENTER to transfer ...(Transfer price: €{$transferPrice})");
                    } else {
                        $this->info("Domain {$domain} is available for registration!");
                        $this->info("Registration price: €{$registrationPrice}");
                        $this->info("Renewal price: €{$renewalPrice}");

                        return true; // Proceed with registration
                    }
                }, name: 'proceed')
                // Step 4: Get auth code for transfer
                ->add(function ($responses) {
                    $isAvailable = $responses['domain_info']['isAvailable'];

                    if (!$isAvailable && $responses['proceed']) {
                        return text(
                            'Enter the authorization code for the domain transfer',
                            required: true,
                        );
                    }

                    return null; // Skip this step for registration
                }, name: 'auth_code')
                // Step 5: DNS configuration
                ->add(function ($responses) {
                    if (!$responses['proceed']) {
                        return null; // User cancelled
                    }

                    return select(
                        'Select DNS configuration',
                        [
                            'hosted' => 'Use Hosted DNS',
                            'custom' => 'Use Custom Nameservers',
                        ],
                    );
                }, name: 'dns_choice')
                // Step 6: Custom nameservers if selected
                ->add(function ($responses) {
                    if (!$responses['proceed'] || $responses['dns_choice'] !== 'custom') {
                        return null; // Skip if not using custom nameservers
                    }

                    return $this->collectNameservers();
                }, name: 'nameservers')
                // Step 7: WHOIS Privacy
                ->add(function ($responses) {
                    if (!$responses['proceed']) {
                        return null; // User cancelled
                    }

                    $supportsWhoisPrivacy = $responses['domain_info']['supportsWhoisPrivacy'];

                    if ($supportsWhoisPrivacy) {
                        return confirm(
                            'Enable WHOIS Privacy?',
                            default: false,
                        );
                    }

                    return false;
                }, name: 'use_whois_privacy')
                // Step 8: Contacts
                ->add(function ($responses) use ($domainClient) {
                    if (!$responses['proceed']) {
                        return null; // User cancelled
                    }

                    $useWhoisPrivacy      = $responses['use_whois_privacy'];
                    $supportsWhoisPrivacy = $responses['domain_info']['supportsWhoisPrivacy'];

                    if ((!$supportsWhoisPrivacy || !$useWhoisPrivacy)
                        && confirm('Would you like to specify contacts' . (!$supportsWhoisPrivacy ? ' (other than default)' : '') . '?', default: false)) {
                        return $this->collectContacts($domainClient);
                    }

                    return null;
                }, name: 'contacts')
                // Step 9: DNSSEC
                ->add(function ($responses) {
                    if (!$responses['proceed']) {
                        return null; // User cancelled
                    }

                    $supportsDnssec = $responses['domain_info']['supportsDnssec'];
                    $tld            = $responses['domain_info']['tld'];

                    if ($supportsDnssec) {
                        if (confirm('Would you like to configure DNSSEC?', default: false)) {
                            return $this->collectDnssecKeys();
                        }
                    } else {
                        $this->warn("DNSSEC is not supported for .{$tld} domains.");
                    }

                    return null;
                }, name: 'dnssec_keys')
                // Step 10: Final confirmation
                ->add(function ($responses) {
                    if (!$responses['proceed']) {
                        return null; // User cancelled
                    }

                    $domain            = $responses['domain'];
                    $isAvailable       = $responses['domain_info']['isAvailable'];
                    $registrationPrice = $responses['domain_info']['registrationPrice'];
                    $transferPrice     = $responses['domain_info']['transferPrice'];

                    $action      = !$isAvailable ? 'transfer' : 'register';
                    $actionPrice = !$isAvailable ? $transferPrice : $registrationPrice;

                    return confirm(
                        "Ready to {$action} {$domain} for €{$actionPrice}. Proceed?",
                        default: false,
                    );
                }, name: 'final_confirmation')
                ->submit();

            // Early exit if user canceled
            if (!$responses['proceed']) {
                outro('Operation cancelled.');

                return 0;
            }

            // Early exit if user didn't confirm the final action
            if (!$responses['final_confirmation']) {
                $domain      = $responses['domain'];
                $isAvailable = $responses['domain_info']['isAvailable'];
                $action      = !$isAvailable ? 'transfer' : 'register';
                outro("Domain {$action} cancelled.");

                return 1;
            }

            // Prepare parameters for domain registration/transfer
            $params = [
                'domain' => $responses['domain'],
            ];

            // Set auth code for transfer
            if (!$responses['domain_info']['isAvailable'] && $responses['auth_code']) {
                $params['auth_code'] = $responses['auth_code'];
            }

            // Set DNS configuration based on choice
            if ($responses['dns_choice'] === 'hosted') {
                // Use hosted DNS
                $params['is_using_hosted_dns'] = true;
            } elseif ($responses['dns_choice'] === 'custom' && $responses['nameservers']) {
                // Use custom nameservers - don't pass is_using_hosted_dns to false
                $params['nameservers'] = $responses['nameservers'];
            }

            // Set WHOIS privacy if enabled
            if ($responses['domain_info']['supportsWhoisPrivacy'] && $responses['use_whois_privacy']) {
                $params['is_whois_privacy_enabled'] = true;
            }

            // Set contacts if provided
            if ($responses['contacts']) {
                $params['contacts'] = $responses['contacts'];
            }

            // Set DNSSEC keys if provided
            if ($responses['dnssec_keys']) {
                $params['dnssec_keys'] = $responses['dnssec_keys'];
            }

            // Register or transfer the domain
            $domain      = $responses['domain'];
            $isAvailable = $responses['domain_info']['isAvailable'];
            $action      = !$isAvailable ? 'transfer' : 'register';

            $domainClient->registerOrTransferDomain($params);

            $this->success("Successfully {$action}ed domain: {$domain}");

            return 0;
        } catch (Exception $e) {
            $this->error($e->getMessage());

            return 1;
        }
    }

    private function collectContacts(Client $domainClient): array
    {
        $contacts     = [];
        $contactTypes = ['owner', 'admin', 'tech', 'billing'];

        // Fetch available contacts
        try {
            $availableContacts = $domainClient->listContacts(['per_page' => 100]);

            // Format contacts for selection
            $contactOptions = [];
            foreach ($availableContacts['data'] as $contact) {
                $label = sprintf(
                    '%s %s%s (%s) - %s',
                    $contact['first_name'],
                    $contact['last_name'],
                    $contact['company_name'] ? ' - ' . $contact['company_name'] : '',
                    $contact['email'],
                    $contact['handle'],
                );

                if ($contact['is_default']) {
                    $label .= ' [Default]';
                }

                $contactOptions[$contact['handle']] = $label;
            }

            if (empty($contactOptions)) {
                $this->warn('No contacts found. Please create contacts first.');
                if (!confirm('Continue without contacts?', default: false)) {
                    throw new RuntimeException('Registration cancelled - no contacts available');
                }

                return [];
            }

            // Add a "Skip" option
            $contactOptions['skip'] = 'Skip this contact type';

            foreach ($contactTypes as $type) {
                $this->info("\nSelecting {$type} contact:");

                $selectedContact = search(
                    label: "Search and select {$type} contact",
                    options: fn () => $contactOptions,
                    placeholder: 'Start typing to search contacts...',
                );

                if ($selectedContact !== 'skip') {
                    $contacts[$type] = $selectedContact;
                }
            }

            return $contacts;

        } catch (Exception $e) {
            $this->warn('Failed to fetch contacts: ' . $e->getMessage());
            if (!confirm('Continue without contacts?', default: false)) {
                throw new RuntimeException('Registration cancelled - could not fetch contacts');
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
        $nameservers    = [];
        $minNameservers = 2;

        $this->info("You need to provide at least {$minNameservers} nameservers.");

        for ($i = 1; $i <= $minNameservers; $i++) {
            $nameserver = [
                'hostname' => text(
                    label: "Enter nameserver #{$i} hostname",
                    required: true,
                    validate: fn ($value) => $this->validateHostname($value),
                ),
            ];

            if (confirm(
                label: "Would you like to add IPv4 for nameserver #{$i}?",
                default: false,
            )) {
                $nameserver['ipv4'] = text(
                    label: 'Enter IPv4 address',
                    validate: fn ($value) => $this->validateIPv4($value),
                );
            }

            if (confirm(
                label: "Would you like to add IPv6 for nameserver #{$i}?",
                default: false,
            )) {
                $nameserver['ipv6'] = text(
                    label: 'Enter IPv6 address',
                    validate: fn ($value) => $this->validateIPv6($value),
                );
            }

            $nameservers[] = $nameserver;
        }

        // Option to add more nameservers
        while (count($nameservers) < 13 && confirm(
            label: 'Would you like to add another nameserver?',
            default: false,
        )) {
            $i          = count($nameservers) + 1;
            $nameserver = [
                'hostname' => text(
                    label: "Enter nameserver #{$i} hostname",
                    required: true,
                    validate: fn ($value) => $this->validateHostname($value),
                ),
            ];

            // Similar IPv4/IPv6 collection as above
            if (confirm(
                label: "Would you like to add IPv4 for nameserver #{$i}?",
                default: false,
            )) {
                $nameserver['ipv4'] = text(
                    label: 'Enter IPv4 address',
                    validate: fn ($value) => $this->validateIPv4($value),
                );
            }

            if (confirm(
                label: "Would you like to add IPv6 for nameserver #{$i}?",
                default: false,
            )) {
                $nameserver['ipv6'] = text(
                    label: 'Enter IPv6 address',
                    validate: fn ($value) => $this->validateIPv6($value),
                );
            }

            $nameservers[] = $nameserver;
        }

        return $nameservers;
    }

    private function collectDnssecKeys(): array
    {
        $keys       = [];
        $algorithms = [
            1  => 'RSA/MD5',
            2  => 'Diffie-Hellman',
            3  => 'DSA/SHA1',
            5  => 'RSA/SHA-1',
            6  => 'DSA-NSEC3-SHA1',
            7  => 'RSASHA1-NSEC3-SHA1',
            8  => 'RSA/SHA-256',
            10 => 'RSA/SHA-512',
            12 => 'GOST R 34.10-2001',
            13 => 'ECDSA Curve P-256 with SHA-256',
            14 => 'ECDSA Curve P-384 with SHA-384',
            15 => 'Ed25519',
            16 => 'Ed448',
            17 => 'INDIRECT',
            23 => 'ECC-GOST',
        ];

        do {
            $key = [
                'public_key' => text(
                    label: 'Enter the public key',
                    required: true,
                ),
                'algorithm'  => select(
                    label: 'Select the DNSSEC algorithm',
                    options: $algorithms,
                ),
                'flags'      => select(
                    label: 'Select the key flags',
                    options: [
                        256 => 'ZSK (Zone Signing Key)',
                        257 => 'KSK (Key Signing Key)',
                    ],
                ),
                'protocol'   => 3,
            ];

            $keys[] = $key;

        } while (confirm(
            label: 'Would you like to add another DNSSEC key?',
            default: false,
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
}
