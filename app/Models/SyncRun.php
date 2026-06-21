<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SyncStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SyncRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'external_source_id',
        'query_definition_id',
        'target_dataset',
        'soql',
        'status',
        'since',
        'limit',
        'records_received',
        'records_inserted',
        'records_changed',
        'records_skipped',
        'records_failed',
        'started_at',
        'finished_at',
        'duration_ms',
        'error_message',
        'meta',
    ];

    protected $casts = [
        'status'           => SyncStatus::class,
        'since'            => 'datetime',
        'started_at'       => 'datetime',
        'finished_at'      => 'datetime',
        'meta'             => 'array',
        'records_received' => 'integer',
        'records_inserted' => 'integer',
        'records_changed'  => 'integer',
        'records_skipped'  => 'integer',
        'records_failed'   => 'integer',
        'duration_ms'      => 'integer',
    ];

    public function externalSource(): BelongsTo
    {
        return $this->belongsTo(ExternalSource::class);
    }

    public function queryDefinition(): BelongsTo
    {
        return $this->belongsTo(QueryDefinition::class);
    }

    public function errors(): HasMany
    {
        return $this->hasMany(SyncError::class);
    }
}
