<?php

namespace App\Commands\Domain\Domain;

use App\Commands\Command;
use App\API\Domain\Client;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\text;

class AvailabilityCommand extends Command
{
    protected $signature   = 'domain:availability {domain?}';
    protected $description = 'Check the availability of a domain';

    public function handle(Client $domainClient): int
    {
        $domain = $this->argument('domain') ?? text('What domain would you like to check?');

        $isAvailable = spin(
            callback: fn () => $domainClient->checkDomainAvailability($domain)['availability'] === 'free',
            message: 'Checking domain availability...',
        );

        if ($isAvailable) {
            $this->success("The domain $domain is available!");
        } else {
            $this->error("The domain $domain is not available.");
        }

        return self::SUCCESS;
    }
}
