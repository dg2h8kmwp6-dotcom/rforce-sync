<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ExternalSource extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'label',
        'config',
        'active',
    ];

    protected $casts = [
        'config' => 'array',
        'active' => 'boolean',
    ];

    public function syncRuns(): HasMany
    {
        return $this->hasMany(SyncRun::class);
    }

    public function rawRecords(): HasMany
    {
        return $this->hasMany(RawRforceRecord::class);
    }
}
