<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RawRforceRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'external_source_id',
        'last_sync_run_id',
        'query_definition_id',
        'source_object_type',
        'source_url',
        'external_id',
        'payload',
        'payload_hash',
        'first_seen_at',
        'last_seen_at',
        'last_synced_at',
    ];

    protected $casts = [
        'payload'        => 'array',
        'first_seen_at'  => 'datetime',
        'last_seen_at'   => 'datetime',
        'last_synced_at' => 'datetime',
    ];

    public function externalSource(): BelongsTo
    {
        return $this->belongsTo(ExternalSource::class);
    }

    public function lastSyncRun(): BelongsTo
    {
        return $this->belongsTo(SyncRun::class, 'last_sync_run_id');
    }

    public function queryDefinition(): BelongsTo
    {
        return $this->belongsTo(QueryDefinition::class);
    }
}
