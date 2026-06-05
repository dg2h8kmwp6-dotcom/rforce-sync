<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NormalizedOpportunity extends Model
{
    use HasFactory;

    protected $table = 'normalized_opportunities';

    protected $guarded = ['id'];

    protected $casts = [
        'extra'                => 'array',
        'amount'               => 'decimal:2',
        'probability'          => 'integer',
        'is_won'               => 'boolean',
        'is_closed'            => 'boolean',
        'close_date'           => 'date',
        'external_created_at'  => 'datetime',
        'external_modified_at' => 'datetime',
        'last_synced_at'       => 'datetime',
    ];

    public function externalSource(): BelongsTo
    {
        return $this->belongsTo(ExternalSource::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(
            NormalizedAccount::class,
            'account_external_id',
            'external_id',
        );
    }
}
