<?php

namespace App\Commands\Account\Auth;

use RuntimeException;
use App\Commands\Command;
use App\Services\AuthService;
use function Laravel\Prompts\info;
use function Laravel\Prompts\spin;

class LogoutCommand extends Command
{
    protected $signature   = 'auth:logout {action?}';
    protected $description = 'Log out of the authenticated Chief Tools account';

    public function handle(AuthService $auth): int
    {
        if ($auth->isAuthenticated()) {
            spin(function () use ($auth) {
                try {
                    $auth->revokeTokens();
                } catch (RuntimeException) {
                    // We don't really care if this fails since it's not critical but a nice-to-have
                }
            }, 'Logging out...');

            $auth->clearAuthData();
        }

        info('Successfully logged out.');

        return self::SUCCESS;
    }
}
