<?php

namespace App\Services;

use App\Models\IssuanceAttempt;
use App\Models\PolicyRequest;
use App\Models\PolicyStateHistory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * MockInsurerService
 *
 * Simulates the external insurance provider.
 * In the real world, this would be an HTTP call to a real insurer.
 * Here, we use a "scenario" field to decide what response to fake.
 *
 * Scenarios supported:
 *   SUCCESS             → returns a policy_number (happy path)
 *   TEMPORARY_FAILURE   → returns an error (retry-able)
 *   DUPLICATE_CALLBACK  → same as SUCCESS but sends callback twice (for testing)
 *   TIMEOUT_AFTER_ISSUE → issues internally but times out (for testing)
 */
class MockInsurerService
{
    /**
     * Simulate submitting to the insurer.
     * Returns an array with 'success', 'policy_number', 'reference', 'error'.
     */
    public function submit(PolicyRequest $policyRequest): array
    {
        // Read the test scenario from an HTTP header (dev/test only).
        // In production this would be replaced with real insurer API logic.
        $scenario = request()->header('X-Mock-Scenario', 'SUCCESS');

        return match ($scenario) {
            'TEMPORARY_FAILURE' => $this->handleTemporaryFailure(),
            'TIMEOUT_AFTER_ISSUE' => $this->handleTimeoutAfterIssue($policyRequest),
            default => $this->handleSuccess($policyRequest),
        };
    }

    /**
     * The callback endpoint receives a simulated event from the insurer.
     * We use this in the DELAYED_CALLBACK and DUPLICATE_CALLBACK scenarios.
     */
    private function handleSuccess(PolicyRequest $policyRequest): array
    {
        return [
            'success' => true,
            'policy_number' => 'POL-' . strtoupper(Str::random(8)),
            'reference' => 'REF-' . strtoupper(Str::random(6)),
            'error' => null,
        ];
    }

    private function handleTemporaryFailure(): array
    {
        return [
            'success' => false,
            'policy_number' => null,
            'reference' => null,
            'error' => 'Insurer temporarily unavailable. Please retry.',
        ];
    }

    private function handleTimeoutAfterIssue(PolicyRequest $policyRequest): array
    {
        // The insurer issues the policy internally, but the response times out.
        // We store the policy number in a way the reconciliation can find it.
        // For the mock, we simulate this by returning a special flag.
        return [
            'success' => false,
            'policy_number' => null,
            'reference' => 'REF-TIMEOUT-' . strtoupper(Str::random(4)),
            'error' => 'Request timed out. Policy may have been issued on provider side.',
            'timeout' => true,
        ];
    }
}
