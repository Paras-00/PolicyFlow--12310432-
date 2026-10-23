<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * PolicyRequest Model
 *
 * Represents a single insurance policy request from an agent.
 * One record = one unique (agent_id + client_request_id) pair.
 *
 * STATUS FLOW:
 * RECEIVED → VALIDATED → SUBMITTED → PENDING_CONFIRMATION → ISSUED
 *                                                          → FAILED
 *                                                          → NEEDS_RECONCILIATION
 */
class PolicyRequest extends Model
{
    // All valid states as constants (easy to reference anywhere)
    const STATUS_RECEIVED              = 'RECEIVED';
    const STATUS_VALIDATED             = 'VALIDATED';
    const STATUS_SUBMITTED             = 'SUBMITTED';
    const STATUS_PENDING_CONFIRMATION  = 'PENDING_CONFIRMATION';
    const STATUS_ISSUED                = 'ISSUED';
    const STATUS_FAILED                = 'FAILED';
    const STATUS_NEEDS_RECONCILIATION  = 'NEEDS_RECONCILIATION';

    // States that are "final" — no further transitions allowed
    const FINAL_STATES = [
        self::STATUS_ISSUED,
        self::STATUS_FAILED,
    ];

    protected $fillable = [
        'client_request_id',
        'agent_id',
        'customer_name',
        'date_of_birth',
        'destination',
        'trip_start',
        'trip_end',
        'plan_code',
        'sum_insured',
        'premium',
        'status',
        'policy_number',
        'provider_reference',
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'trip_start'    => 'date',
        'trip_end'      => 'date',
        'premium'       => 'decimal:2',
    ];

    // ---- Relationships ----

    public function issuanceAttempts(): HasMany
    {
        return $this->hasMany(IssuanceAttempt::class);
    }

    public function providerEvents(): HasMany
    {
        return $this->hasMany(ProviderEvent::class);
    }

    public function stateHistory(): HasMany
    {
        return $this->hasMany(PolicyStateHistory::class)->orderBy('created_at');
    }

    // ---- Helper Methods ----

    /** Check if this request is in a final state (cannot be changed) */
    public function isFinal(): bool
    {
        return in_array($this->status, self::FINAL_STATES);
    }

    /** How many times have we tried to submit to the insurer? */
    public function attemptCount(): int
    {
        return $this->issuanceAttempts()->count();
    }
}
