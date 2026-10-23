<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ProviderEvent Model
 *
 * When the mock insurer sends a callback (webhook), we store it here.
 * The event_id column has a UNIQUE constraint at the DB level.
 *
 * RELIABILITY CASE: If the same callback arrives twice, the second
 * insert will fail (duplicate key). We catch that and safely ignore it.
 * This is called "idempotent event processing."
 */
class ProviderEvent extends Model
{
    protected $fillable = [
        'policy_request_id',
        'event_id',
        'event_type',
        'payload',
        'processed',
    ];

    protected $casts = [
        'processed' => 'boolean',
    ];

    public function policyRequest(): BelongsTo
    {
        return $this->belongsTo(PolicyRequest::class);
    }
}
