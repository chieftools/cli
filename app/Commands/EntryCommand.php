<?php

namespace App\Commands;

use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Helper\DescriptorHelper;

abstract class EntryCommand extends Command
{
    protected $hidden = false;

    protected function configure(): void
    {
        parent::configure();

        $this->addArgument('subcommand', InputArgument::OPTIONAL, 'The subcommand to execute', null, $this->getSubCommandNames());

        // This is to prevent errors for invalid arguments and options intended for the subcommands we are going to run
        $this->ignoreValidationErrors();
    }

    public function handle(): int
    {
        $subCommandName = $this->argument('subcommand') ?? $this->input->getFirstArgument();

        if ($subCommandName === null || $subCommandName === 'help') {
            $subCommandForHelp = $this->getCommandFromName(
                $subCommandName = $this->input->getArguments()[1] ?? '',
            );

            if ($subCommandForHelp !== null) {
                $subCommandForHelp->setName("chief {$this->name} {$subCommandName}");
                $helper = new DescriptorHelper;
                $helper->describe($this->output, $subCommandForHelp);

                return self::INVALID;
            }

            $this->showHelp();

            return self::INVALID;
        }

        return $this->runSubCommand($subCommandName);
    }

    private function showHelp(): void
    {
        $this->line($this->description);

        $this->line('');

        $this->boldLine('USAGE');
        $this->line("  chief {$this->name} <command> [flags]");

        $this->line('');

        $commands = $this->getSubCommandNames();

        $maxCommandLength = collect($commands)->map(fn (string $name) => strlen($name))->max();

        $this->boldLine('AVAILABLE COMMANDS');
        foreach ($commands as $command) {
            $this->line(sprintf(
                '  %s:%s %s',
                $command,
                str_repeat(' ', $maxCommandLength - strlen($command) + 2),
                $this->getCommandFromName($command)?->getDescription() ?? 'No description available',
            ));
        }

        $this->line('');
    }

    /** @return array<string, class-string<\App\Commands\Command>> */
    abstract protected function getSubCommands(): array;

    private function runSubCommand(?string $command): int
    {
        $commandInstance = $this->getCommandFromName($command);

        if ($commandInstance === null) {
            $this->showHelp();

            return self::INVALID;
        }

        /** @var \Symfony\Component\Console\Input\ArgvInput $input */
        $input = $this->input;

        return $commandInstance->run(new ArgvInput($input->getRawTokens(true)), $this->output);
    }

    private function getSubCommandNames(): array
    {
        return array_keys($this->getSubCommands());
    }

    private function getCommandFromName(string $name): ?Command
    {
        $commands = $this->getSubCommands();

        if (!array_key_exists($name, $commands)) {
            return null;
        }

        return $this->getCommandFromClass($commands[$name]);
    }
}
