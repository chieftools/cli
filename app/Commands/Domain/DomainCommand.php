<?php

namespace App\Commands\Domain;

use App\Commands\EntryCommand;
use App\Commands\Domain\Domain\ListCommand;
use App\Commands\Domain\Domain\RegisterCommand;
use App\Commands\Domain\Domain\AvailabilityCommand;

class DomainCommand extends EntryCommand
{
    protected $name        = 'domain';
    protected $description = 'Manage domains';

    protected function getSubCommands(): array
    {
        return [
            'list'         => ListCommand::class,
            'register'     => RegisterCommand::class,
            'availability' => AvailabilityCommand::class,
        ];
    }
}
