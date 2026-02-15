<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class DriveSyncState extends Model
{
    use HasUuids;

    protected $fillable = [
        'key',
        'root_folder_id',
        'shared_drive_id',
        'last_start_page_token',
        'last_synced_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'last_synced_at' => 'datetime',
            'metadata' => 'array',
        ];
    }
}
