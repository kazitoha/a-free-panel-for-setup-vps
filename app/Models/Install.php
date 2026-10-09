<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A long-running panel job executed as root (phpMyAdmin install, site apply…).
 */
class Install extends Model
{
    public const string STATUS_PENDING = 'pending';

    public const string STATUS_RUNNING = 'running';

    public const string STATUS_SUCCESS = 'success';

    public const string STATUS_FAILED = 'failed';

    protected $fillable = [
        'type',
        'label',
        'command',
        'status',
        'message',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_RUNNING], true);
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::STATUS_SUCCESS, self::STATUS_FAILED], true);
    }

    /**
     * Status for display: detects a job that never started or stalled.
     */
    public function displayStatus(): string
    {
        if ($this->status === self::STATUS_PENDING && $this->created_at?->diffInSeconds(now()) > 30) {
            return 'stalled';
        }

        if ($this->status === self::STATUS_RUNNING
            && ($this->started_at ?? $this->created_at)?->diffInSeconds(now()) > 1800) {
            return 'stalled';
        }

        return $this->status;
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }
}
