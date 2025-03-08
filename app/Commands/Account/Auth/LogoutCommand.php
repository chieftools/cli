<?php

namespace App\Commands\Account\Auth;

use App\Commands\Command;

class LogoutCommand extends Command
{
    protected $signature   = 'auth:logout {action?}';
    protected $description = 'Log out of the authenticated Chief Tools account';

    public function handle(): int
    {
        // @TODO: Also try to revoke the (access/refresh) token when logging out
        authService()->clearApiKey();

        $this->info('Successfully logged out.');

        return self::SUCCESS;
    }
}
