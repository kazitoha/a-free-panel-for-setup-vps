<?php

namespace App\Console\Commands;

use App\Exceptions\InvalidCron;
use App\Models\CronJob;
use App\Support\CronSpec;
use App\Support\HostCommand;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('panel:cron:exec
    {job : Cron job id}')]
#[Description('Run one panel-managed cron job and record its result')]
class PanelCronExec extends Command
{
    protected $hidden = true;

    public function __construct(private readonly HostCommand $command)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $job = CronJob::find((int) $this->argument('job'));

        if ($job === null) {
            $this->error('Cron job not found.');

            return self::FAILURE;
        }

        if (! $job->enabled) {
            $this->line('Job is disabled; skipping.');

            return self::SUCCESS;
        }

        try {
            $command = CronSpec::validateCommand($job->command);
        } catch (InvalidCron $exception) {
            $job->update([
                'last_run_at' => now(),
                'last_status' => 'failed',
                'last_exit' => 1,
                'last_output' => mb_substr($exception->getMessage(), 0, 2000),
            ]);
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $parts = explode(' ', $command);
        $result = $this->command->run($parts, 300.0);

        $output = trim($result['stdout']."\n".$result['stderr']);
        $status = $result['exit'] === 0 ? 'ok' : 'failed';

        $job->update([
            'last_run_at' => now(),
            'last_status' => $status,
            'last_exit' => $result['exit'],
            'last_output' => $output !== '' ? mb_substr($output, 0, 2000) : null,
        ]);

        if ($output !== '') {
            $this->output->write($output."\n");
        }

        if ($status === 'failed') {
            $this->error("Command exited with code {$result['exit']}.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
