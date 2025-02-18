<?php

namespace App\Commands;

use App\Services\AuthService;
use App\Services\ConfigManager;
use LaravelZero\Framework\Commands\Command as BaseCommand;

abstract class Command extends BaseCommand
{
    public const SUCCESS = 0;
    public const FAILURE = 1;
    public const INVALID = 2;

    protected AuthService $auth;
    protected ConfigManager $config;


    public function __construct()
    {
        parent::__construct();

        $this->auth = new AuthService();
        $this->config = new ConfigManager();
    }


    public function console($string, $type, $verbosity = null): void
    {
        $this->$type($string, $verbosity);
    }

    public function info($string, $verbosity = null): void
    {
        parent::info("<fg=blue>==></><options=bold> {$string}</>", $verbosity);
    }

    public function error($string, $verbosity = null): void
    {
        parent::error("<fg=red>==></><options=bold> {$string}</>", $verbosity);
    }

    public function success($string, $verbosity = null): void
    {
        parent::info("<fg=green>==></><options=bold> {$string}</>", $verbosity);
    }

    public function warn($string, $verbosity = null): void
    {
        parent::warn("<fg=yellow>==></><options=bold> {$string}</>", $verbosity);
    }
}
