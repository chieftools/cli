<?php

namespace App\Commands\DomainChief;

use App\Commands\Command;
use App\Services\DomainChiefService;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\text;

class DomainAvailabilityCommand extends Command
{
    private DomainChiefService $domainService;

    protected $signature = 'domain:availability {domain?}';

    protected $description = 'Check the availability of a domain';

    public function __construct(DomainChiefService $domainService)
    {
        parent::__construct();
        $this->domainService = $domainService;
    }

    public function handle()
    {
        $domain = $this->argument('domain') ?? text('What domain would you like to check?');

        $isAvailable = spin(
            callback: fn () => $this->domainService->checkDomainAvailability($domain)['availability'] == 'free',
            message: 'Checking domain availability...'
        );

        if ($isAvailable) {
            $this->success("The domain $domain is available!");
        } else {
            $this->error("The domain $domain is not available.");
        }

    }
}
