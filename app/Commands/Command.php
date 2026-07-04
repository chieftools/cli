<?php

namespace App\Commands;

use Throwable;
use App\Services\AuthService;
use LaravelZero\Framework\Commands\Command as BaseCommand;

abstract class Command extends BaseCommand
{
    protected $hidden = true;

    protected function success($string, $verbosity = null): void
    {
        $this->line("<fg=green>=></><options=bold> {$string}</>", $verbosity);
    }

    protected function boldLine($string, $verbosity = null): void
    {
        $this->line("<options=bold>{$string}</>", $verbosity);
    }

    protected function ensureTokenHasScopes(AuthService $auth, string $scopeConfigKey): bool
    {
        $requiredScopes = config($scopeConfigKey, []);

        if (!is_array($requiredScopes)) {
            $this->error("Invalid required scope configuration: {$scopeConfigKey}");

            return false;
        }

        try {
            $missingScopes = $auth->getMissingScopes($requiredScopes);
        } catch (Throwable $e) {
            $this->error('Unable to verify authentication permissions: ' . $e->getMessage());

            return false;
        }

        if (empty($missingScopes)) {
            return true;
        }

        $this->error(sprintf(
            'Your authentication token is missing required permissions: %s. Run `chief auth login` and approve the required permissions.',
            implode(', ', $missingScopes),
        ));

        return false;
    }

    protected function getCommandFromClass(string $class): ?Command
    {
        if (!class_exists($class)) {
            return null;
        }

        /** @var \App\Commands\Command $command */
        $command = $this->resolveCommand($class);

        $command->setApplication($this->getApplication());
        /** @phpstan-ignore-next-line we are passing the correct type here */
        $command->setLaravel($this->getLaravel());

        return $command;
    }
}
