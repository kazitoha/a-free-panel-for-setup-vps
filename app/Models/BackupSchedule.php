<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A per-target backup schedule: how often (1-24 evenly spread slots per day)
 * and how long (retention in days) a database or project backup is kept.
 */
class BackupSchedule extends Model
{
    public const TYPE_DATABASE = 'database';

    public const TYPE_PROJECT = 'project';

    protected $fillable = [
        'target_type',
        'target_id',
        'times_per_day',
        'retention_days',
        'enabled',
        'next_run_at',
        'last_run_at',
        'last_status',
        'last_error',
        'last_path',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'next_run_at' => 'datetime',
            'last_run_at' => 'datetime',
        ];
    }

    public function database(): HasOne
    {
        return $this->hasOne(Database::class, 'id', 'target_id');
    }

    public function project(): HasOne
    {
        return $this->hasOne(Project::class, 'id', 'target_id');
    }

    /**
     * The target row for this schedule, resolved from target_type/target_id.
     */
    public function target(): Database|Project|null
    {
        return match ($this->target_type) {
            self::TYPE_DATABASE => Database::find($this->target_id),
            self::TYPE_PROJECT => Project::find($this->target_id),
            default => null,
        };
    }

    public function targetLabel(): string
    {
        return match ($this->target_type) {
            self::TYPE_DATABASE => Database::whereKey($this->target_id)->value('name')
                ?? "database #{$this->target_id}",
            self::TYPE_PROJECT => Project::whereKey($this->target_id)->value('name')
                ?? "project #{$this->target_id}",
            default => "target #{$this->target_id}",
        };
    }

    /**
     * Hourly slots for times_per_day, evenly spread across 24h, unique and sorted.
     *
     * @return list<int>
     */
    public static function slots(int $timesPerDay): array
    {
        $n = max(1, min(24, $timesPerDay));
        $hours = [];

        for ($i = 0; $i < $n; $i++) {
            $hours[(int) floor($i * 24 / $n)] = true;
        }

        ksort($hours);

        return array_keys($hours);
    }

    /**
     * The next slot strictly after $from: today if a slot remains, otherwise
     * tomorrow's first slot.
     */
    public function computeNextRunAt(?CarbonInterface $from = null): CarbonInterface
    {
        $from = $from ?? now();
        $slots = self::slots($this->times_per_day);
        $first = $slots[0];

        foreach ($slots as $hour) {
            $candidate = $from->copy()->startOfDay()->addHours($hour);

            if ($candidate->greaterThan($from)) {
                return $candidate;
            }
        }

        return $from->copy()->addDay()->startOfDay()->addHours($first);
    }
}
