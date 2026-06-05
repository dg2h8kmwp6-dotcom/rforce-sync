<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NormalizedAccount extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $casts = [
        'extra'                => 'array',
        'annual_revenue'       => 'decimal:2',
        'employee_count'       => 'integer',
        'external_created_at'  => 'datetime',
        'external_modified_at' => 'datetime',
        'last_synced_at'       => 'datetime',
    ];

    public function externalSource(): BelongsTo
    {
        return $this->belongsTo(ExternalSource::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(NormalizedContact::class, 'account_external_id', 'external_id')
            ->where('normalized_contacts.external_source_id', $this->external_source_id);
    }

    public function opportunities(): HasMany
    {
        return $this->hasMany(NormalizedOpportunity::class, 'account_external_id', 'external_id')
            ->where('normalized_opportunities.external_source_id', $this->external_source_id);
    }
}
