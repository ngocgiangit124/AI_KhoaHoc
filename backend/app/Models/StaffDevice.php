<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StaffDevice extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'device_hash',
        'last_ip',
        'user_agent',
        'first_seen_at',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }
}
