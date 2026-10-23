<?php

namespace App\Http\Controllers;

use App\Models\PolicyRequest;
use App\Services\PolicyIssuanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * MockInsurerController
 *
 * Simulates the external insurer's callback endpoint.
 * In production, this would be a webhook sent by a real insurance provider.
 * Here, it's a local endpoint we can call ourselves to simulate responses.
 *
 * ENDPOINT:
 *   POST /api/mock-insurer/callback
 */
class MockInsurerController extends Controller
{
    public function __construct(private PolicyIssuanceService $service) {}

    /**
     * POST /api/mock-insurer/callback
     *
     * Simulates a callback from the insurer (e.g. "your policy has been issued").
     *
     * RELIABILITY CASE 2: Duplicate Callback
     * Send the same event_id twice → only processed once.
     *
     * Example request body:
     * {
     *   "event_id": "EVT-ABC123",
     *   "event_type": "POLICY_ISSUED",
     *   "policy_request_id": 1,
     *   "policy_number": "POL-XYZ999",
     *   "reference": "REF-001"
     * }
     */
    public function callback(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'event_id'          => 'required|string',
            'event_type'        => 'required|in:POLICY_ISSUED,POLICY_FAILED',
            'policy_request_id' => 'required|integer|exists:policy_requests,id',
            'policy_number'     => 'nullable|string',
            'reference'         => 'nullable|string',
        ]);

        $policyRequest = PolicyRequest::findOrFail($validated['policy_request_id']);

        $result = $this->service->handleCallback($policyRequest, $validated);

        return response()->json($result);
    }
}
