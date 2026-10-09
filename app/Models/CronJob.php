<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A panel-managed cron job written to /etc/cron.d/panel. The command must
 * pass the CronSpec allowlist both when saved and when executed.
 */
class CronJob extends Model
{
    protected $fillable = [
        'name',
        'cron_minute',
        'cron_hour',
        'cron_dom',
        'cron_month',
        'cron_dow',
        'command',
        'enabled',
        'last_run_at',
        'last_status',
        'last_exit',
        'last_output',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'last_run_at' => 'datetime',
            'last_exit' => 'integer',
        ];
    }

    /**
     * The five cron schedule fields, in order.
     *
     * @return array{0: string, 1: string, 2: string, 3: string, 4: string}
     */
    public function scheduleFields(): array
    {
        return [
            $this->cron_minute,
            $this->cron_hour,
            $this->cron_dom,
            $this->cron_month,
            $this->cron_dow,
        ];
    }
}
