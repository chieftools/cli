<?php

namespace App\Commands;

use App\Commands\Account\AuthCommand;
use App\Commands\Domain\DomainCommand;
use Symfony\Component\Console\Input\ArgvInput;

class HelpCommand extends Command
{
    protected $name        = 'help';
    protected $signature   = 'help {command_name?}';
    protected $description = 'Show help information.';

    private ?Command $command = null;

    private static array $commands = [
        'auth'        => AuthCommand::class,
        'domain'      => DomainCommand::class,
        'self-update' => 'Try to update the Chief Tools CLI to the latest version',
    ];

    private static array $flags = [
        '--help'    => 'Display help for command',
        '--version' => 'Show Chief Tools CLI version',
    ];

    public function handle(): int
    {
        if ($this->command) {
            $subCommandName = null;

            if ($this->input instanceof ArgvInput) {
                $subCommandName = $this->input->getRawTokens(true)[0] ?? null;
            }

            return $this->runCommand($this->command, ['help', $subCommandName], $this->output);
        }

        $this->line('Work with Chief Tools from the command line.');

        $this->line('');

        $this->boldLine('USAGE');
        $this->line('  chief <command> <subcommand> [flags]');

        $this->line('');

        $maxColumnWidth = $this->getMaxColumnWidth();

        $this->boldLine('AVAILABLE COMMANDS');
        foreach (self::$commands as $commandName => $commandClass) {
            $this->line(sprintf(
                '  %s:%s %s',
                $commandName,
                str_repeat(' ', $maxColumnWidth - strlen($commandName) + 2),
                $this->getCommandFromClass($commandClass)?->getDescription() ?? $commandClass,
            ));
        }

        $this->line('');

        $this->boldLine('FLAGS');
        foreach (self::$flags as $flagName => $flagDescription) {
            $this->line(sprintf(
                '  %s:%s %s',
                $flagName,
                str_repeat(' ', $maxColumnWidth - strlen($flagName) + 2),
                $flagDescription,
            ));
        }

        $this->line('');

        return self::SUCCESS;
    }

    public function setCommand(Command $command): void
    {
        $this->command = $command;
    }

    private function getMaxColumnWidth(): int
    {
        $maxCommandLength = collect(self::$commands)->keys()->map(fn (string $name) => strlen($name))->max();

        $maxFlagsLength = collect(self::$flags)->keys()->map(fn (string $name) => strlen($name))->max();

        return max($maxCommandLength, $maxFlagsLength);
    }
}
