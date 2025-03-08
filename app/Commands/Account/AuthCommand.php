<?php

namespace App\Commands\Account;

use App\Commands\EntryCommand;
use App\Commands\Account\Auth\LoginCommand;
use App\Commands\Account\Auth\LogoutCommand;
use App\Commands\Account\Auth\WhoamiCommand;

class AuthCommand extends EntryCommand
{
    protected $signature   = 'auth';
    protected $description = 'Authenticate with Chief Tools';

    protected function getSubCommands(): array
    {
        return [
            'login'  => LoginCommand::class,
            'logout' => LogoutCommand::class,
            'whoami' => WhoamiCommand::class,
        ];
    }
}
