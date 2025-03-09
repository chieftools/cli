<?php

namespace App\Commands\Account\Auth;

use RuntimeException;
use App\Commands\Command;
use Illuminate\Support\Arr;
use App\Services\AuthService;
use function Laravel\Prompts\info;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\error;
use function Laravel\Prompts\warning;

class WhoamiCommand extends Command
{
    protected $signature   = 'auth:whoami';
    protected $description = 'Display active account and authentication state';

    public function handle(AuthService $auth): int
    {
        if (!$auth->isAuthenticated()) {
            warning('Not authenticated. Use "chief auth login" to authenticate.');

            return self::FAILURE;
        }

        try {
            $userInfo = spin(static fn () => $auth->getUserInfo(), 'Retrieving user information...');

            $team = Arr::first($userInfo['teams']);

            info(sprintf(
                'Authenticated as <options=bold>%s</> (<options=bold>%s</>) for team <options=bold>%s</> (<options=bold>%s</>)',
                $userInfo['name'],
                $userInfo['email'],
                $team['name'],
                $team['slug'],
            ));

            $auth->updateTeamInfo($team['slug'], $team['name']);

            return self::SUCCESS;
        } catch (RuntimeException $e) {
            error("Failed to retrieve user information ({$e->getMessage()})");

            return self::FAILURE;
        }
    }
}
