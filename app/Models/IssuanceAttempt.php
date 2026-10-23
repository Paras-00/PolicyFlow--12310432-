<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * IssuanceAttempt Model
 *
 * Every time we call the mock insurer, we create one of these records.
 * If we retry, attempt_number goes up (1, 2, 3...).
 * This way we always know what happened with that attempt.
 */
class IssuanceAttempt extends Model
{
    protected $fillable = [
        'policy_request_id',
        'attempt_number',
        'status',
        'provider_response',
        'error_message',
    ];

    public function policyRequest(): BelongsTo
    {
        return $this->belongsTo(PolicyRequest::class);
    }
}
