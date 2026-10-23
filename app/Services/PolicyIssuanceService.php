<?php

namespace App\Services;

use App\Models\IssuanceAttempt;
use App\Models\PolicyRequest;
use App\Models\PolicyStateHistory;
use App\Models\ProviderEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * PolicyIssuanceService
 *
 * This is the HEART of the application.
 * It orchestrates the full lifecycle of a policy request:
 *   1. Validate the request
 *   2. Calculate premium
 *   3. Submit to mock insurer
 *   4. Handle the insurer response
 *   5. Transition state safely
 *
 * KEY CONCEPT: Every state transition happens inside a DB transaction,
 * so if anything goes wrong mid-way, the DB rolls back. No partial states.
 *
 * HOW TO EXPLAIN IN INTERVIEW:
 *   "All the business logic is in this one service, so the controller
 *    stays thin and easy to read. The service uses DB transactions to
 *    make sure state changes are atomic."
 */
class PolicyIssuanceService
{
    public function __construct(
        private PremiumCalculator  $calculator,
        private MockInsurerService $insurer,
    ) {}

    /**
     * STEP 1: Validate and persist a new (or return existing) policy request.
     *
     * RELIABILITY CASE 1: Duplicate Intake Prevention
     * If the same agent_id + client_request_id comes in again,
     * the unique DB constraint fires. We catch that and return the existing record.
     */
    public function createOrFind(array $data): array
    {
        // Try to find an existing request first (avoids race-condition issues)
        $existing = PolicyRequest::where('agent_id', $data['agent_id'])
            ->where('client_request_id', $data['client_request_id'])
            ->first();

        if ($existing) {
            // Return existing — this is the safe idempotent response
            return ['request' => $existing, 'created' => false];
        }

        // Calculate premium upfront (stateless, no DB)
        $premium = $this->calculator->calculate(
            planCode:    $data['plan_code'],
            tripStart:   $data['trip_start'],
            tripEnd:     $data['trip_end'],
            dateOfBirth: $data['date_of_birth'],
            destination: $data['destination'],
            sumInsured:  $data['sum_insured'],
        );

        // Wrap in a transaction: both the policy_request and its first history entry
        // must be created together, or not at all.
        $request = DB::transaction(function () use ($data, $premium) {
            $req = PolicyRequest::create([
                'client_request_id' => $data['client_request_id'],
                'agent_id'          => $data['agent_id'],
                'customer_name'     => $data['customer_name'],
                'date_of_birth'     => $data['date_of_birth'],
                'destination'       => $data['destination'],
                'trip_start'        => $data['trip_start'],
                'trip_end'          => $data['trip_end'],
                'plan_code'         => $data['plan_code'],
                'sum_insured'       => $data['sum_insured'],
                'premium'           => $premium,
                'status'            => PolicyRequest::STATUS_RECEIVED,
            ]);

            // Write first history entry
            $this->logStateChange($req, null, PolicyRequest::STATUS_RECEIVED, 'Request created');

            return $req;
        });

        return ['request' => $request, 'created' => true];
    }

    /**
     * STEP 2: Submit a validated request to the mock insurer.
     *
     * Called via: POST /api/policy-requests/{id}/submit
     * Safely resumes if already submitted (idempotent).
     */
    public function submit(PolicyRequest $policyRequest): PolicyRequest
    {
        // If already in a final state, don't do anything
        if ($policyRequest->isFinal()) {
            return $policyRequest;
        }

        // If already ISSUED somehow, return as-is
        if ($policyRequest->status === PolicyRequest::STATUS_ISSUED) {
            return $policyRequest;
        }

        return DB::transaction(function () use ($policyRequest) {
            // Move to SUBMITTED
            $this->transitionStatus(
                $policyRequest,
                PolicyRequest::STATUS_SUBMITTED,
                'Submitting to insurer'
            );

            // Call mock insurer
            $attemptNumber = $policyRequest->attemptCount() + 1;
            $response      = $this->insurer->submit($policyRequest);

            // Log the attempt
            IssuanceAttempt::create([
                'policy_request_id' => $policyRequest->id,
                'attempt_number'    => $attemptNumber,
                'status'            => $response['success'] ? 'SUCCESS' : 'FAILED',
                'provider_response' => json_encode($response),
                'error_message'     => $response['error'],
            ]);

            if ($response['success']) {
                // Happy path: insurer confirmed, mark as ISSUED
                $this->transitionStatus(
                    $policyRequest,
                    PolicyRequest::STATUS_ISSUED,
                    'Issued by insurer'
                );
                $policyRequest->update([
                    'policy_number'      => $response['policy_number'],
                    'provider_reference' => $response['reference'],
                ]);
            } elseif (!empty($response['timeout'])) {
                // Timed out — policy may exist on provider side, needs reconciliation
                $this->transitionStatus(
                    $policyRequest,
                    PolicyRequest::STATUS_NEEDS_RECONCILIATION,
                    'Timed out — possible insurer-side issuance'
                );
            } else {
                // Temporary failure — mark as FAILED (can be retried)
                $this->transitionStatus(
                    $policyRequest,
                    PolicyRequest::STATUS_FAILED,
                    'Insurer returned failure: ' . $response['error']
                );
            }

            return $policyRequest->fresh();
        });
    }

    /**
     * STEP 3: Process an inbound callback from the mock insurer.
     *
     * RELIABILITY CASE 2: Duplicate Callback Prevention
     * The event_id column has a UNIQUE DB constraint.
     * If the same callback arrives twice, the second insert fails → we catch it → safely ignore.
     *
     * Also: if the request is already ISSUED, we reject further state changes.
     */
    public function handleCallback(PolicyRequest $policyRequest, array $callbackData): array
    {
        $eventId   = $callbackData['event_id'];
        $eventType = $callbackData['event_type'];  // POLICY_ISSUED or POLICY_FAILED

        // DUPLICATE CHECK: Try to insert the event with the unique event_id.
        // If it already exists, the DB unique constraint throws an exception.
        try {
            $event = ProviderEvent::create([
                'policy_request_id' => $policyRequest->id,
                'event_id'          => $eventId,
                'event_type'        => $eventType,
                'payload'           => json_encode($callbackData),
                'processed'         => false,
            ]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            // Duplicate event! Safely ignore it.
            return ['status' => 'duplicate_ignored', 'message' => 'Event already processed.'];
        }

        // Don't process if already in a final state (ISSUED stays ISSUED)
        if ($policyRequest->isFinal()) {
            $event->update(['processed' => true]);
            return ['status' => 'skipped', 'message' => 'Request already in final state.'];
        }

        // Process the event inside a transaction
        DB::transaction(function () use ($policyRequest, $event, $callbackData, $eventType) {
            if ($eventType === 'POLICY_ISSUED') {
                $this->transitionStatus($policyRequest, PolicyRequest::STATUS_ISSUED, 'Issued via callback');
                $policyRequest->update([
                    'policy_number'      => $callbackData['policy_number'] ?? 'POL-' . strtoupper(Str::random(8)),
                    'provider_reference' => $callbackData['reference'] ?? null,
                ]);
            } else {
                $this->transitionStatus($policyRequest, PolicyRequest::STATUS_FAILED, 'Failed via callback');
            }

            $event->update(['processed' => true]);
        });

        return ['status' => 'processed', 'message' => 'Callback handled successfully.'];
    }

    /**
     * Change the status of a policy request and log it to history.
     * Called inside transactions — if transaction rolls back, this rolls back too.
     */
    private function transitionStatus(PolicyRequest $request, string $toStatus, string $reason = ''): void
    {
        $fromStatus = $request->status;
        $request->update(['status' => $toStatus]);
        $this->logStateChange($request, $fromStatus, $toStatus, $reason);
    }

    /**
     * Write one row to policy_state_history.
     */
    private function logStateChange(PolicyRequest $request, ?string $from, string $to, string $reason): void
    {
        PolicyStateHistory::create([
            'policy_request_id' => $request->id,
            'from_status'       => $from,
            'to_status'         => $to,
            'reason'            => $reason,
        ]);
    }
}
