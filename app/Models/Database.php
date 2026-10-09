<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A MySQL database created through the panel. The password is stored
 * encrypted and is only ever revealed back to the admin on demand.
 */
class Database extends Model
{
    protected $fillable = [
        'name',
        'username',
        'password',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'encrypted',
        ];
    }
}
