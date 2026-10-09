<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A git-hosted PHP project deployed under the panel's www directory.
 */
class Project extends Model
{
    protected $fillable = [
        'name',
        'repo_url',
        'branch',
        'domain',
        'php_version',
        'path',
        'database_id',
        'last_deployed_at',
        'last_deploy_status',
        'last_deploy_message',
    ];

    protected function casts(): array
    {
        return [
            'last_deployed_at' => 'datetime',
        ];
    }

    public function database(): BelongsTo
    {
        return $this->belongsTo(Database::class);
    }
}
