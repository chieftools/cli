<?php

namespace App\Commands\Account\Auth;

use RuntimeException;
use App\Commands\Command;
use Illuminate\Support\Arr;
use App\Services\AuthService;
use Stayallive\RandomTokens\RandomToken;
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

            $tokenInfo = spin(static fn () => $auth->getTokenInfo(), 'Retrieving token information...');

            $parsedToken = RandomToken::fromString($auth->getBearerToken());

            $team = Arr::first($userInfo['teams']);

            $this->boldLine('chief.app');
            $this->line(sprintf(
                '  <fg=green>✓</> Logged in to chief.app account <options=bold>%s</> (<options=bold>%s</>)',
                $userInfo['name'],
                $userInfo['email'],
            ));
            $this->line(sprintf(
                '  Token type: %s',
                match ($parsedToken->prefix) {
                    'cto'   => 'OAuth',
                    'ctp'   => 'Personal access token',
                    default => "Unknown ({$parsedToken->prefix})",
                },
            ));
            $this->line(sprintf(
                '  Token: <options=bold>%s_%s</>',
                $parsedToken->prefix,
                str_repeat('*', strlen($parsedToken->random . $parsedToken->checksum)),
            ));
            $this->line(sprintf(
                '  Token scopes: <options=bold>%s</>',
                implode(', ', explode(' ', $tokenInfo['scope'])),
            ));

            // If we retrieved the info anyway, let's also make sure our "cache" is up-to-date
            $auth->updateTeamInfo($team['slug'], $team['name']);

            return self::SUCCESS;
        } catch (RuntimeException $e) {
            error("Failed to retrieve user information ({$e->getMessage()})");

            return self::FAILURE;
        }
    }
}
