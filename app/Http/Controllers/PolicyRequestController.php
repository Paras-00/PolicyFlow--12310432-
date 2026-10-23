<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePolicyRequest;
use App\Models\PolicyRequest;
use App\Services\PolicyIssuanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * PolicyRequestController
 *
 * Thin controller — all logic lives in PolicyIssuanceService.
 * This controller only:
 *   1. Validates input (via FormRequest)
 *   2. Calls the service
 *   3. Returns a JSON response
 *
 * ENDPOINTS:
 *   POST   /api/policy-requests           → create or return existing
 *   GET    /api/policy-requests           → list with optional status filter
 *   GET    /api/policy-requests/{id}      → single request + audit trail
 *   POST   /api/policy-requests/{id}/submit → submit to insurer
 */
class PolicyRequestController extends Controller
{
    public function __construct(private PolicyIssuanceService $service) {}

    /**
     * POST /api/policy-requests
     * Create a new policy request (or return existing if duplicate).
     *
     * Returns 201 for new requests, 200 for existing (idempotent).
     */
    public function store(StorePolicyRequest $request): JsonResponse
    {
        $result = $this->service->createOrFind($request->validated());

        $statusCode = $result['created'] ? 201 : 200;

        return response()->json([
            'data'    => $this->formatRequest($result['request']),
            'created' => $result['created'],
            'message' => $result['created']
                ? 'Policy request created successfully.'
                : 'Existing policy request returned (duplicate intake detected).',
        ], $statusCode);
    }

    /**
     * GET /api/policy-requests
     * List all requests, optionally filtered by status.
     *
     * Query params:
     *   ?status=ISSUED         → filter by status
     *   ?per_page=10           → pagination
     */
    public function index(Request $request): JsonResponse
    {
        $query = PolicyRequest::query();

        // Optional status filter
        if ($request->has('status')) {
            $query->where('status', $request->input('status'));
        }

        $requests = $query->orderByDesc('created_at')
            ->paginate($request->input('per_page', 15));

        return response()->json([
            'data'  => $requests->map(fn($r) => $this->formatRequest($r)),
            'meta'  => [
                'current_page' => $requests->currentPage(),
                'total'        => $requests->total(),
                'per_page'     => $requests->perPage(),
                'last_page'    => $requests->lastPage(),
            ],
        ]);
    }

    /**
     * GET /api/policy-requests/{id}
     * Get a single request with full audit trail (state history).
     */
    public function show(PolicyRequest $policyRequest): JsonResponse
    {
        $policyRequest->load(['stateHistory', 'issuanceAttempts']);

        return response()->json([
            'data' => array_merge(
                $this->formatRequest($policyRequest),
                [
                    'state_history'    => $policyRequest->stateHistory,
                    'attempt_count'    => $policyRequest->issuanceAttempts->count(),
                    'attempts'         => $policyRequest->issuanceAttempts,
                ]
            ),
        ]);
    }

    /**
     * POST /api/policy-requests/{id}/submit
     * Submit policy to the mock insurer. Idempotent — safe to call multiple times.
     */
    public function submit(PolicyRequest $policyRequest): JsonResponse
    {
        $updated = $this->service->submit($policyRequest);

        return response()->json([
            'data'    => $this->formatRequest($updated),
            'message' => "Policy is now in status: {$updated->status}",
        ]);
    }

    /**
     * Format a PolicyRequest into a clean API response array.
     */
    private function formatRequest(PolicyRequest $r): array
    {
        return [
            'id'                 => $r->id,
            'client_request_id'  => $r->client_request_id,
            'agent_id'           => $r->agent_id,
            'customer_name'      => $r->customer_name,
            'destination'        => $r->destination,
            'trip_start'         => $r->trip_start?->format('Y-m-d'),
            'trip_end'           => $r->trip_end?->format('Y-m-d'),
            'plan_code'          => $r->plan_code,
            'sum_insured'        => $r->sum_insured,
            'premium'            => $r->premium ? '₹' . number_format($r->premium, 2) : null,
            'premium_raw'        => $r->premium,
            'status'             => $r->status,
            'policy_number'      => $r->policy_number,
            'provider_reference' => $r->provider_reference,
            'created_at'         => $r->created_at?->toISOString(),
            'updated_at'         => $r->updated_at?->toISOString(),
        ];
    }
}
