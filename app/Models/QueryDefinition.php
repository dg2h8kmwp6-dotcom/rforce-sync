<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QueryDefinition extends Model
{
    use HasFactory;

    protected $fillable = [
        'external_source_id',
        'name',
        'description',
        'soql',
        'target_object',
        'transformer_class',
        'enabled',
        'schedule',
        'last_run_at',
    ];

    protected $casts = [
        'enabled'     => 'boolean',
        'last_run_at' => 'datetime',
    ];

    public function externalSource(): BelongsTo
    {
        return $this->belongsTo(ExternalSource::class);
    }

    public function syncRuns(): HasMany
    {
        return $this->hasMany(SyncRun::class);
    }

    public function rawRecords(): HasMany
    {
        return $this->hasMany(RawRforceRecord::class);
    }

    /**
     * Resolve the transformer to use for normalizing this query's rows.
     * Falls back to config('rforce.objects.{target_object}.transformer').
     */
    public function resolvedTransformerClass(): ?string
    {
        if ($this->transformer_class !== null && class_exists($this->transformer_class)) {
            return $this->transformer_class;
        }

        if ($this->target_object === null) {
            return null;
        }

        $configured = config("rforce.objects.{$this->target_object}.transformer");
        return is_string($configured) && class_exists($configured) ? $configured : null;
    }
}
