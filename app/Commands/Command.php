<?php

namespace App\Commands;

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
