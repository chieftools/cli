<?php

namespace App\Commands;

use App\Commands\Command;
use Exception;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use function Laravel\Prompts\text;
use function Laravel\Prompts\select;
use function Laravel\Prompts\spin;

class AuthCommand extends Command
{
    protected const COMMAND_DESCRIPTIONS = [
        'login' => 'Authenticate with your API key',
        'logout' => 'Clear your API key',
        'whoami' => 'Display information about the currently authenticated user',
    ];

    protected const BROWSER_COMMANDS = [
        'Darwin' => 'open',
        'Windows' => 'start',
        'Linux' => 'xdg-open',
    ];

    protected $signature = 'auth {action?}';
    protected $description = 'Authenticate with Chief Tools';

    public function handle()
    {
        $action = $this->argument('action');

        return match ($action) {
            'login' => $this->login(),
            'logout' => $this->logout(),
            'whoami' => $this->whoami(),
            default => $this->displayHelp(),
        };
    }

    protected function login(): int
    {
        try {
            if ($this->auth->hasApiKey()) {
                return $this->handleExistingAuth();
            }

            return $this->handleNewAuth();
        } catch (Exception $e) {
            return $this->handleError('Login failed', $e);
        }
    }

    protected function logout(): int
    {
        $this->auth->clearApiKey();
        $this->info('Successfully logged out.');
        return Command::SUCCESS;
    }

    protected function handleExistingAuth(): int
    {
        if (!$this->auth->hasTeam()) {
            $this->selectTeam();
            $this->info('Team selected successfully.');
        }
        return $this->whoami();
    }

    protected function handleNewAuth(): int
    {
        $authData = $this->auth->initiateDeviceAuth();
        $this->info('Opening browser for authentication...');
        $this->openBrowser($authData['verification_uri']);

        $tokenData = $this->pollForToken($authData);
        if (!$tokenData) {
            $this->error('Authorization request expired, please try again!');
            return Command::FAILURE;
        }

        try {
            $userInfo = $this->completeAuthentication($tokenData, $authData);
            $this->displayLoginSuccess($userInfo);
            return Command::SUCCESS;
        } catch (Exception $e) {
            return $this->handleError('Authentication failed', $e);
        }
    }

    protected function pollForToken(array $authData): ?array
    {
        return spin(
            callback: fn () => $this->auth->pollForToken($authData),
            message: 'Waiting for authorization...'
        );
    }

    /**
     * @throws Exception
     */
    protected function completeAuthentication(array $tokenData, array $authData): array
    {
        $userInfo = $this->auth->completeAuthentication($tokenData, $authData['userinfo_endpoint']);

        if (!$this->isValidUserInfo($userInfo)) {
            throw new Exception('Unable to fetch user information');
        }

        return $userInfo;
    }

    protected function displayLoginSuccess(array $userInfo): void
    {
        $teamName = Arr::first($userInfo['teams'])['name'];
        $this->info("Successfully logged in as {$userInfo['name']} ({$userInfo['email']}) with team {$teamName}.");
    }

    protected function openBrowser(string $url): void
    {
        $command = self::BROWSER_COMMANDS[PHP_OS_FAMILY] ?? null;

        if ($command) {
            exec("{$command} {$url}");
            return;
        }

        $this->info("Please open this URL in your browser: {$url}");
    }

    //protected function selectTeam(): void
    //{
    //    $userInfo = $this->auth->getUserInfo();
    //    $teams = $userInfo['teams'] ?? [];
    //
    //    if (empty($teams)) {
    //        throw new \Exception('No teams available for this user');
    //    }
    //
    //    if (count($teams) === 1) {
    //        $this->auth->setTeam(Arr::first($teams)['slug']);
    //        return;
    //    }
    //
    //    $this->handleTeamSelection($teams);
    //}

    //protected function handleTeamSelection(array $teams): void
    //{
    //    $options = collect($teams)->pluck('name', 'slug')->toArray();
    //
    //    $selectedTeam = select(
    //        label: 'Select your team',
    //        options: $options,
    //    );
    //
    //    $this->auth->setTeam($selectedTeam);
    //}

    protected function whoami(): int
    {
        try {
            if (!$this->auth->hasApiKey()) {
                $this->warn('Not logged in. Use "auth login" to authenticate.');
                return Command::FAILURE;
            }

            $userInfo = $this->auth->getUserInfo();
            if (!$this->isValidUserInfo($userInfo)) {
                throw new Exception('Invalid user data received');
            }

            $this->displayUserInfo($userInfo);

            return Command::SUCCESS;
        } catch (Exception $e) {
            return $this->handleError('Failed to get user info', $e);
        }
    }

    protected function isValidUserInfo(array $userInfo): bool
    {
        return isset($userInfo['name'], $userInfo['email']);
    }

    protected function displayUserInfo(array $userInfo): void
    {
        $teamName = Arr::first($userInfo['teams'])['name'];
        $this->info("Currently logged in as: {$userInfo['name']} ({$userInfo['email']}) with team {$teamName}");
    }

    protected function displayHelp(): int
    {
        $this->info('To authenticate with Chief Tools, you can use the following commands:');

        foreach (self::COMMAND_DESCRIPTIONS as $command => $description) {
            $this->info("  - auth {$command}: {$description}");
        }

        return Command::SUCCESS;
    }

    protected function handleError(string $message, Exception $e): int
    {
        $this->error("{$message}: {$e->getMessage()}");
        return Command::FAILURE;
    }
}
