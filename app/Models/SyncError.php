<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SyncError extends Model
{
    use HasFactory;

    protected $fillable = [
        'sync_run_id',
        'object_type',
        'external_id',
        'category',
        'message',
        'context',
    ];

    protected $casts = [
        'context' => 'array',
    ];

    public function syncRun(): BelongsTo
    {
        return $this->belongsTo(SyncRun::class);
    }
}
