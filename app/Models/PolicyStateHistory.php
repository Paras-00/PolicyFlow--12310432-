<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * PolicyStateHistory Model
 *
 * Every time a policy_request changes its status, we write a row here.
 * Example: from RECEIVED → VALIDATED, or from SUBMITTED → ISSUED.
 *
 * This gives a full audit trail — you can always see the complete
 * history of what happened and why.
 */
class PolicyStateHistory extends Model
{
    // Tell Laravel to use this exact table name (instead of auto-pluralizing)
    protected $table = 'policy_state_history';

    protected $fillable = [
        'policy_request_id',
        'from_status',
        'to_status',
        'reason',
    ];

    public function policyRequest(): BelongsTo
    {
        return $this->belongsTo(PolicyRequest::class);
    }
}
