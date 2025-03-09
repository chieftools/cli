<?php

namespace App\Commands\Account\Auth;

use Exception;
use App\Commands\Command;
use App\Services\AuthService;
use function Laravel\Prompts\info;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\error;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\confirm;

class LoginCommand extends Command
{
    private const BROWSER_COMMANDS = [
        'Darwin'  => 'open',
        'Windows' => 'start',
        'Linux'   => 'xdg-open',
    ];

    protected $signature   = 'auth:login';
    protected $description = 'Authenticate with a Chief Tools account';

    public function handle(AuthService $auth): int
    {
        if ($auth->isAuthenticated()) {
            $reAuthenticate = confirm('You are already authenticated. Do you want to re-authenticate?');

            if (!$reAuthenticate) {
                return self::SUCCESS;
            }

            $this->runCommand(LogoutCommand::class, [], $this->output);
        }

        return $this->authenticate($auth);
    }

    private function authenticate(AuthService $auth): int
    {
        $authData = $auth->initiateDeviceAuth();

        intro('Opening browser for authentication...');

        $this->openBrowser($authData['verification_uri_complete']);

        $tokenData = spin(
            callback: static fn () => $auth->pollForToken($authData),
            message: 'Waiting for authentication...',
        );

        if (!$tokenData) {
            error('Authentication request expired, please try again!');

            return self::FAILURE;
        }

        try {
            $auth->completeAuthentication($tokenData);

            info('Successfully authenticated!');

            return self::SUCCESS;
        } catch (Exception $e) {
            error("Authentication failed ({$e->getMessage()})");

            return self::FAILURE;
        }
    }

    private function openBrowser(string $url): void
    {
        $command = self::BROWSER_COMMANDS[PHP_OS_FAMILY] ?? null;

        if ($command) {
            exec("{$command} {$url}");

            return;
        }

        info("Please open this URL in your browser: {$url}");
    }
}
