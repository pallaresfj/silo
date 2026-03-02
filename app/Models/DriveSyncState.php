<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class DriveSyncState extends Model
{
    use HasUuids;

    public const EXECUTION_STATUS_QUEUED = 'queued';

    public const EXECUTION_STATUS_RUNNING = 'running';

    public const EXECUTION_STATUS_COMPLETED = 'completed';

    public const EXECUTION_STATUS_FAILED = 'failed';

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

    /**
     * @return array<string, mixed>
     */
    public function getExecutionMetadata(): array
    {
        $execution = $this->metadata['execution'] ?? null;

        return is_array($execution) ? $execution : [];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function putExecutionMetadata(array $attributes): static
    {
        $metadata = $this->metadata ?? [];
        $execution = $this->getExecutionMetadata();

        $metadata['execution'] = array_merge($execution, $attributes);
        $this->metadata = $metadata;

        return $this;
    }
}
