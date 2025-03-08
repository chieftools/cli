<?php

namespace App\Commands\Account\Auth;

use Exception;
use RuntimeException;
use App\Commands\Command;
use Illuminate\Support\Arr;
use function Laravel\Prompts\spin;

class LoginCommand extends Command
{
    protected const BROWSER_COMMANDS = [
        'Darwin'  => 'open',
        'Windows' => 'start',
        'Linux'   => 'xdg-open',
    ];

    protected $signature   = 'auth:login';
    protected $description = 'Authenticate with a Chief Tools account';

    public function handle(): int
    {
        // Check if we are already authenticated and show the user info instead
        // @TODO: Ask the user if they want to re-authenticate if they are already logged in
        if (authService()->hasApiKey()) {
            return $this->runCommand(WhoamiCommand::class, [], $this->output);
        }

        try {
            return $this->authenticate();
        } catch (Exception $e) {
            return $this->handleError('Login failed', $e);
        }
    }

    private function authenticate(): int
    {
        $authData = authService()->initiateDeviceAuth();

        $this->info('Opening browser for authentication...');
        $this->openBrowser($authData['verification_uri']);

        $tokenData = $this->pollForToken($authData);

        if (!$tokenData) {
            $this->error('Authorization request expired, please try again!');

            return self::FAILURE;
        }

        try {
            $userInfo = $this->completeAuthentication($tokenData, $authData);

            $this->displayLoginSuccess($userInfo);

            return self::SUCCESS;
        } catch (Exception $e) {
            return $this->handleError('Authentication failed', $e);
        }
    }

    private function pollForToken(array $authData): ?array
    {
        return spin(
            callback: fn () => authService()->pollForToken($authData),
            message: 'Waiting for authorization...',
        );
    }

    private function completeAuthentication(array $tokenData, array $authData): array
    {
        $userInfo = authService()->completeAuthentication($tokenData, $authData['userinfo_endpoint']);

        if (!$this->isValidUserInfo($userInfo)) {
            throw new RuntimeException('Unable to fetch user information');
        }

        return $userInfo;
    }

    private function displayLoginSuccess(array $userInfo): void
    {
        $teamName = Arr::first($userInfo['teams'])['name'];
        $this->info("Successfully logged in as {$userInfo['name']} ({$userInfo['email']}) with team {$teamName}.");
    }

    private function openBrowser(string $url): void
    {
        $command = self::BROWSER_COMMANDS[PHP_OS_FAMILY] ?? null;

        if ($command) {
            exec("{$command} {$url}");

            return;
        }

        $this->info("Please open this URL in your browser: {$url}");
    }

    private function isValidUserInfo(array $userInfo): bool
    {
        return isset($userInfo['name'], $userInfo['email']);
    }

    private function handleError(string $message, Exception $e): int
    {
        $this->error("{$message}: {$e->getMessage()}");

        return self::FAILURE;
    }
}
