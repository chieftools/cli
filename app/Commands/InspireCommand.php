<?php

namespace App\Commands;

use Illuminate\Console\Scheduling\Schedule;

use function Termwind\render;

class InspireCommand extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'inspire {name=Artisan}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'Display an inspiring quote';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        render(<<<'HTML'
            <div class="">
                <div class="px-1 bg-blue-300 text-black"> INFO </div>
                <em class="ml-1">
                  Wat vind je hier van?
                </em>
            </div>
        HTML);

        render(<<<'HTML'
            <div class="">
                <div class="px-1 bg-green-300 text-black"> SUCCESS </div>
                <em class="ml-1">
                  Wat vind je hier van?
                </em>
            </div>
        HTML);

        render(<<<'HTML'
            <div class="">
                <div class="px-1 bg-red-300 text-black"> ERROR </div>
                <em class="ml-1">
                  Wat vind je hier van?
                </em>
            </div>
        HTML);

        render(<<<'HTML'
            <div class="">
                <div class="px-1 bg-orange-300 text-black"> WARNING </div>
                <em class="ml-1">
                  Wat vind je hier van?
                </em>
            </div>
        HTML);

        $this->info('Inspiring quote for ' . $this->argument('name') . ':');
        $this->success('Simplicity is the ultimate sophistication.');
        $this->error('The only way to do great work is to love what you do.');
        $this->warn('Success is not final, failure is not fatal: it is the courage to continue that counts.');

    }

    /**
     * Define the command's schedule.
     */
    public function schedule(Schedule $schedule): void
    {
        // $schedule->command(static::class)->everyMinute();
    }
}
