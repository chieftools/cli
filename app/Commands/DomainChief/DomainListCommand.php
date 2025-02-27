<?php

namespace App\Commands\DomainChief;

use App\Commands\Command;

class DomainListCommand extends Command
{
    protected $signature = 'domain:list
        {--page=1 : Page number for listing}
        {--per-page=25 : Items per page}
        {--query= : Filter domains by name}
        {--expand=* : Expand related data (tld, contacts)}
        {--format=table : Output format (table, json)}
        {--detailed : Show detailed domain information}';

    protected $description = 'List all domains';

    public function handle()
    {

    }
}
