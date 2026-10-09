<?php

namespace App\Console\Commands;

use App\Exceptions\InvalidCron;
use App\Models\CronJob;
use App\Support\CronSpec;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('panel:cron:sync')]
#[Description('Rewrite /etc/cron.d/panel from the panel-managed cron jobs')]
class PanelCronSync extends Command
{
    protected $hidden = true;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $file = (string) config('panel.cron_file');
        $jobs = CronJob::orderBy('id')->get()->filter->enabled->values();

        $lines = [
            '# Managed by VPS Panel — do not edit by hand. Changes will be overwritten.',
            'SHELL=/bin/bash',
            'PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
            '',
            '# Scheduler tick: runs due backup schedules every minute.',
            '* * * * * root /usr/local/panel/run panel:scheduler:tick >> /var/log/panel-scheduler.log 2>&1',
            '',
            '# Update check: fetch the upstream branch and record pending releases.',
            '17 * * * * root /usr/local/panel/run panel:update:check >> /var/log/panel-update.log 2>&1',
        ];

        $written = 0;

        foreach ($jobs as $job) {
            try {
                $command = CronSpec::validateCommand($job->command);
                $fields = CronSpec::validateFields($job->scheduleFields());
            } catch (InvalidCron $exception) {
                $this->warn("Skipped job #{$job->id}: {$exception->getMessage()}");

                continue;
            }

            $name = (string) preg_replace('/[^\x20-\x7E]/', ' ', $job->name);

            $lines[] = '';
            $lines[] = "# {$name}";
            $lines[] = implode(' ', $fields).' root '.$command.' >> /var/log/panel-cron.log 2>&1';

            $written++;
        }

        $dir = dirname($file);

        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            $this->error("Could not create {$dir}.");

            return self::FAILURE;
        }

        if (@file_put_contents($file, implode("\n", $lines)."\n") === false) {
            $this->error("Could not write {$file}.");

            return self::FAILURE;
        }

        @chmod($file, 0644);

        $this->info("Wrote {$written} cron job(s) + scheduler tick to {$file}.");

        return self::SUCCESS;
    }
}
