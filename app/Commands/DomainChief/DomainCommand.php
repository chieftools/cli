<?php

namespace App\Commands\DomainChief;

use App\Commands\Command;
use App\Services\DomainChiefService;
use App\Traits\Auth;
use Carbon\Carbon;

class DomainCommand extends Command
{
    use Auth;

    private DomainChiefService $domainService;

    private const VALID_EXPAND_VALUES = ['tld', 'contacts'];

    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'domain {action? : Action to perform (list, register)}
        {--page=1 : Page number for listing}
        {--per-page=25 : Items per page}
        {--query= : Filter domains by name}
        {--expand=* : Expand related data (tld, contacts)}
        {--format=table : Output format (table, json)}
        {--detailed : Show detailed domain information}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Domain management commands';

    public function __construct(DomainChiefService $domainService)
    {
        parent::__construct();
        $this->domainService = $domainService;
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {

        if ($this->requiresAuth() && !$this->checkAuth()) {
            return Command::FAILURE;
        }

        $action = $this->argument('action');

        switch ($action) {
            case 'list':
                return $this->listDomains();

            case 'register':
                return $this->registerDomain();

            default:
                $this->info('Available domain commands:');
                $this->line('  domain list        List all registered domains');
                $this->line('    --page          Page number (default: 1)');
                $this->line('    --per-page      Items per page (default: 25)');
                $this->line('    --query         Filter domains by name');
                $this->line('    --expand        Expand related data (tld, contacts)');
                $this->line('    --format        Output format (table, json)');
                $this->line('    --detailed      Show detailed domain information');
                $this->line('  domain register    Register a new domain');
                return Command::SUCCESS;
        }
    }

    /**
     * List all domains
     */
    private function listDomains()
    {
        try {
            $options = [
                'page' => (int) $this->option('page'),
                'per_page' => (int) $this->option('per-page'),
            ];

            if ($this->option('query')) {
                $options['query'] = $this->option('query');
            }

            // Process expand options
            $expandOptions = $this->processExpandOptions();
            if (!empty($expandOptions)) {
                $options['expand'] = $expandOptions;
            }

            $response = $this->domainService->listDomains($options);

            if (empty($response['data'])) {
                $this->info('No domains found.');
                return Command::SUCCESS;
            }

            if ($this->option('format') === 'json') {
                $this->line(json_encode($response, JSON_PRETTY_PRINT));
                return Command::SUCCESS;
            }

            if ($this->option('detailed')) {
                return $this->displayDetailedDomains($response['data']);
            }

            return $this->displayBasicDomains($response);
        } catch (\Exception $e) {
            $this->error('Failed to list domains: ' . $e->getMessage());
            return Command::FAILURE;
        }
    }

    /**
     * Process and validate expand options
     *
     * @return array
     */
    private function processExpandOptions(): array
    {
        $expand = $this->option('expand');
        if (empty($expand)) {
            return [];
        }

        // Convert all inputs to an array of individual values
        $processedExpand = [];

        // Handle array of values (from multiple --expand options)
        foreach ((array)$expand as $item) {
            // Handle comma-separated values in each item
            $values = str_contains($item, ',')
                ? array_map('trim', explode(',', $item))
                : [trim($item)];

            $processedExpand = array_merge($processedExpand, $values);
        }

        // Filter valid options and remove duplicates
        return array_values(array_unique(array_filter(
            $processedExpand,
            fn($value) => in_array($value, self::VALID_EXPAND_VALUES, true)
        )));
    }

    /**
     * Display basic domain information in a table
     */
    private function displayBasicDomains(array $response): int
    {
        $headers = ['Domain', 'Status', 'Auto-Renew', 'Renews / Expires At'];
        $rows = [];

        foreach ($response['data'] as $domain) {
            $rows[] = [
                $domain['domain'] ?? 'N/A',
                $domain['status'] ?? 'N/A',
                $domain['is_autorenew_enabled'] ? 'Yes' : 'No',
                $this->formatDate($domain['expires_at'] ?? $domain['renews_at'] ?? null)
            ];
        }

        $this->table($headers, $rows);
        $this->displayPaginationInfo($response['meta'] ?? []);

        return Command::SUCCESS;
    }

    private function formatDate(?string $dateString): string
    {
        if (empty($dateString)) {
            return 'N/A';
        }

        return Carbon::parse($dateString)->format('Y-m-d H:i');

    }

    /**
     * Display detailed domain information
     */
    private function displayDetailedDomains(array $domains): int
    {
        $expandOptions = $this->processExpandOptions();

        foreach ($domains as $domain) {
            $this->info("\nDomain: {$domain['domain']}");
            $this->line('----------------------------------------');
            $this->line("Status: {$domain['status']}");
            $this->line("Type: {$domain['type']}");
            $this->line("Premium: " . ($domain['is_premium'] ? 'Yes' : 'No'));
            $this->line("Locked: " . ($domain['is_locked'] ? 'Yes' : 'No'));
            $this->line("Auto-Renew: " . ($domain['is_autorenew_enabled'] ? 'Yes' : 'No'));
            $this->line("WHOIS Privacy: " . ($domain['is_whois_privacy_enabled'] ? 'Yes' : 'No'));
            $this->line("Hosted DNS: " . ($domain['is_using_hosted_dns'] ? 'Yes' : 'No'));
            $this->line("DNSSEC: " . ($domain['is_dnssec_enabled'] ? 'Yes' : 'No'));

            // Display TLD information if expanded
            if (in_array('tld', $expandOptions, true) && isset($domain['tld']) && is_array($domain['tld'])) {
                $this->displayTldInformation($domain['tld']);
            }

            // Display contacts if expanded
            if (in_array('contacts', $expandOptions, true) && !empty($domain['contacts']) && is_array($domain['contacts'])) {
                $this->displayContactInformation($domain['contacts']);
            } elseif (!empty($domain['contacts'])) {
                // Show basic contact information
                $this->line("\nContact Handles:");
                foreach ($domain['contacts'] as $type => $handle) {
                    $this->line("  {$type}: {$handle}");
                }
            }

            if (!empty($domain['nameservers'])) {
                $this->line("\nNameservers:");
                foreach ($domain['nameservers'] as $ns) {
                    $this->line("  {$ns['hostname']}");
                    if (isset($ns['ipv4'])) $this->line("    IPv4: {$ns['ipv4']}");
                    if (isset($ns['ipv6'])) $this->line("    IPv6: {$ns['ipv6']}");
                }
            }

            if (!empty($domain['dnssec_keys'])) {
                $this->line("\nDNSSEC Keys:");
                foreach ($domain['dnssec_keys'] as $key) {
                    $this->line("  Algorithm: {$key['algorithm']}");
                    $this->line("  Flags: {$key['flags']}");
                    $this->line("  Protocol: {$key['protocol']}");
                    $this->line("  Public Key: {$key['public_key']}");
                }
            }

            $this->line("\nDates:");
            $this->line("  Renews At: {$domain['renews_at']}");
            $this->line("  Expires At: {$domain['expires_at']}");

            if (isset($domain['renewal_price'])) {
                $convertedPrice = number_format($domain['renewal_price'] / 100, 2);
                $this->line("\nRenewal Price: EUR {$convertedPrice} without tax");
            }
        }

        return Command::SUCCESS;
    }

    /**
     * Display detailed contact information
     *
     * @param array $contacts Array of contact information
     */
    private function displayContactInformation(array $contacts): void
    {
        $this->line("\nDetailed Contact Information:");

        // Define contact types to display
        $contactTypes = ['owner', 'admin', 'tech', 'billing'];

        foreach ($contactTypes as $type) {
            if (!isset($contacts[$type])) {
                continue;
            }

            $contact = $contacts[$type];
            $this->line("\n  " . ucfirst($type) . " Contact:");

            // Display company information if available
            if (!empty($contact['company_name'])) {
                $this->line("    Company: {$contact['company_name']}");
                if (!empty($contact['company_registration_number'])) {
                    $this->line("    Company Registration: {$contact['company_registration_number']}");
                }
                if (!empty($contact['vat_registration'])) {
                    $this->line("    VAT Number: {$contact['vat_registration']}");
                }
            }

            // Display personal information
            $this->line("    Name: {$contact['first_name']} {$contact['last_name']}");

            // Display address
            $this->line("    Address:");
            $address = [];
            if (!empty($contact['address_street'])) {
                $address[] = "      {$contact['address_street']}";
                if (!empty($contact['address_house_number'])) {
                    $address[0] .= " {$contact['address_house_number']}";
                }
            }
            if (!empty($contact['address_postal_code']) || !empty($contact['address_city'])) {
                $address[] = "      " . trim("{$contact['address_postal_code']} {$contact['address_city']}");
            }
            if (!empty($contact['address_country_code'])) {
                $address[] = "      {$contact['address_country_code']}";
            }
            foreach ($address as $line) {
                $this->line($line);
            }

            // Display contact information
            if (!empty($contact['email'])) {
                $emailStatus = !empty($contact['email_verification_status'])
                    ? " ({$contact['email_verification_status']})"
                    : '';
                $this->line("    Email: {$contact['email']}{$emailStatus}");
            }

            if (!empty($contact['phone'])) {
                $this->line("    Phone: {$contact['phone']}");
            }

            // Display handle and default status
            $this->line("    Handle: {$contact['handle']}");
            $this->line("    Default Contact: " . (!empty($contact['is_default']) ? 'Yes' : 'No'));
        }
    }

    /**
     * Display TLD information
     */
    private function displayTldInformation(array $tld): void
    {
        $this->line("\nTLD Information:");
        $this->line("  Name: {$tld['name']}");

        // Handle Types
        if (!empty($tld['handle_types'])) {
            $this->line("  Handle Types: " . implode(', ', $tld['handle_types']));
        }

        // Transfer Type
        if (isset($tld['transfer_type'])) {
            $this->line("  Transfer Type: {$tld['transfer_type']}");
        }

        // Pricing Information
        if (isset($tld['registration_price'])) {
            $registrationPrice = number_format($tld['registration_price'] / 100, 2);
            $this->line("  Registration Price: EUR {$registrationPrice}");
        }
        if (isset($tld['renewal_price'])) {
            $renewalPrice = number_format($tld['renewal_price'] / 100, 2);
            $this->line("  Renewal Price: EUR {$renewalPrice}");
        }
        if (isset($tld['transfer_price'])) {
            $transferPrice = number_format($tld['transfer_price'] / 100, 2);
            $this->line("  Transfer Price: EUR {$transferPrice}");
        }

        // DNSSEC Information
        if (isset($tld['supports_dnssec'])) {
            $this->line("  Supports DNSSEC: " . ($tld['supports_dnssec'] ? 'Yes' : 'No'));
        }
        if (!empty($tld['supported_dnssec_algorithms'])) {
            $this->line("  Supported DNSSEC Algorithms: " . implode(', ', $tld['supported_dnssec_algorithms']));
        }

        // WHOIS Privacy
        if (isset($tld['supports_whois_privacy'])) {
            $this->line("  Supports WHOIS Privacy: " . ($tld['supports_whois_privacy'] ? 'Yes' : 'No'));
        }
    }

    /**
     * Display pagination information
     */
    private function displayPaginationInfo(array $meta): void
    {
        if (!empty($meta)) {
            $this->line('');
            $this->info(sprintf(
                'Showing page %d of %d (Total: %d domains)',
                $meta['current_page'] ?? 1,
                $meta['last_page'] ?? 1,
                $meta['total'] ?? 0
            ));
        }
    }

    /**
     * Register a new domain
     */
    private function registerDomain()
    {
        $this->info('Domain registration is not implemented yet.');
        return Command::SUCCESS;
    }
}
