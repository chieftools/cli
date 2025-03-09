<?php

namespace App\Commands\Account\Auth;

use Exception;
use RuntimeException;
use App\Commands\Command;
use Illuminate\Support\Arr;

class WhoamiCommand extends Command
{
    protected $signature   = 'auth:whoami';
    protected $description = 'Display active account and authentication state';

    public function handle(): int
    {
        try {
            if (!authService()->isAuthenticated()) {
                $this->warn('Not logged in. Use "auth login" to authenticate.');

                return self::FAILURE;
            }

            $userInfo = authService()->getUserInfo();
            if (!$this->isValidUserInfo($userInfo)) {
                throw new RuntimeException('Invalid user data received');
            }

            $this->displayUserInfo($userInfo);

            return self::SUCCESS;
        } catch (RuntimeException $e) {
            return $this->handleError('Failed to get user info', $e);
        }
    }

    private function isValidUserInfo(array $userInfo): bool
    {
        return isset($userInfo['name'], $userInfo['email']);
    }

    private function displayUserInfo(array $userInfo): void
    {
        $teamName = Arr::first($userInfo['teams'])['name'];
        $this->info("Currently logged in as: {$userInfo['name']} ({$userInfo['email']}) with team {$teamName}");
    }

    private function handleError(string $message, Exception $e): int
    {
        $this->error("{$message}: {$e->getMessage()}");

        return self::FAILURE;
    }
}
