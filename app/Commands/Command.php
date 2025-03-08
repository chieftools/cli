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
}
