<?php

namespace Tests\Feature;

use App\Models\PolicyRequest;
use App\Models\ProviderEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PolicyRequestTest — Feature Tests
 *
 * These cover all the acceptance criteria from the assignment PDF.
 *
 * Run with: php artisan test
 *
 * HOW TO EXPLAIN IN INTERVIEW:
 *   "I use RefreshDatabase so each test starts with a clean slate.
 *    Tests simulate real HTTP requests — no mocking needed for basic scenarios."
 */
class PolicyRequestTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------------
    // Shared helper — a valid policy request payload
    // -----------------------------------------------------------------------

    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'client_request_id' => 'AGT-2026-0001',
            'agent_id' => 'AGENT-17',
            'customer_name' => 'Sample Customer',
            'date_of_birth' => '1998-04-10',
            'destination' => 'INTERNATIONAL',
            'trip_start' => '2026-11-10',
            'trip_end' => '2026-11-14',
            'plan_code' => 'PLUS',
            'sum_insured' => 300000,
        ], $overrides);
    }

    // -----------------------------------------------------------------------
    // TEST 1: Valid request creates a policy with correct premium (₹225.00)
    // -----------------------------------------------------------------------

    public function test_valid_request_returns_201_and_correct_premium(): void
    {
        $response = $this->postJson('/api/policy-requests', $this->validPayload());

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'RECEIVED')
            ->assertJsonPath('data.premium_raw', '225.00')
            ->assertJsonPath('created', true);
    }

    // -----------------------------------------------------------------------
    // TEST 2: trip_end before trip_start is rejected with 422
    // -----------------------------------------------------------------------

    public function test_invalid_trip_dates_are_rejected(): void
    {
        $response = $this->postJson('/api/policy-requests', $this->validPayload([
            'trip_start' => '2026-11-14',
            'trip_end' => '2026-11-10',  // END is before START → invalid
        ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['trip_end']);
    }

    // -----------------------------------------------------------------------
    // TEST 3: Unsupported plan code is rejected with 422
    // -----------------------------------------------------------------------

    public function test_unsupported_plan_code_is_rejected(): void
    {
        $response = $this->postJson('/api/policy-requests', $this->validPayload([
            'plan_code' => 'GOLD', // not a valid plan
        ]));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['plan_code']);
    }

    // -----------------------------------------------------------------------
    // TEST 4: Repeated agent_id + client_request_id returns 200, not 201
    // RELIABILITY CASE 1: Duplicate Intake Prevention
    // -----------------------------------------------------------------------

    public function test_duplicate_intake_returns_200_not_201(): void
    {
        // First submission → 201 (created)
        $this->postJson('/api/policy-requests', $this->validPayload())
            ->assertStatus(201);

        // Second, identical submission → 200 (existing returned)
        $response = $this->postJson('/api/policy-requests', $this->validPayload());

        $response->assertStatus(200)
            ->assertJsonPath('created', false)
            ->assertJsonFragment(['message' => 'Existing policy request returned (duplicate intake detected).']);

        // Make sure only ONE record was created in the database
        $this->assertDatabaseCount('policy_requests', 1);
    }

    // -----------------------------------------------------------------------
    // TEST 5: Successful provider call produces exactly one policy number
    // -----------------------------------------------------------------------

    public function test_successful_submission_produces_exactly_one_policy_number(): void
    {
        // Create the request first
        $create = $this->postJson('/api/policy-requests', $this->validPayload());
        $create->assertStatus(201);

        $id = $create->json('data.id');

        // Submit to mock insurer (scenario: SUCCESS by default)
        $submit = $this->postJson("/api/policy-requests/{$id}/submit");
        $submit->assertStatus(200)
            ->assertJsonPath('data.status', 'ISSUED');

        // The policy_number must exist and be a single value
        $policyNumber = $submit->json('data.policy_number');
        $this->assertNotNull($policyNumber);

        // Verify in DB that there is exactly ONE policy with this number
        $this->assertDatabaseCount('policy_requests', 1);
        $this->assertDatabaseHas('policy_requests', [
            'id' => $id,
            'status' => 'ISSUED',
            'policy_number' => $policyNumber,
        ]);
    }

    // -----------------------------------------------------------------------
    // TEST 6: Duplicate callback is idempotent — ISSUED state stays ISSUED
    // RELIABILITY CASE 2: Duplicate Callback Prevention
    // -----------------------------------------------------------------------

    public function test_duplicate_callback_is_safely_ignored(): void
    {
        // Create an ISSUED request directly (no need to submit)
        $req = PolicyRequest::create(array_merge($this->validPayload(), [
            'premium' => 225.00,
            'status' => PolicyRequest::STATUS_ISSUED,
            'policy_number' => 'POL-EXISTING',
        ]));

        $callbackPayload = [
            'event_id' => 'EVT-UNIQUE-001',
            'event_type' => 'POLICY_ISSUED',
            'policy_request_id' => $req->id,
            'policy_number' => 'POL-FROM-CALLBACK',
            'reference' => 'REF-001',
        ];

        // Send first callback → processed
        $this->postJson('/api/mock-insurer/callback', $callbackPayload)
            ->assertStatus(200);

        // Send the SAME callback again (duplicate) → safely ignored
        $duplicate = $this->postJson('/api/mock-insurer/callback', $callbackPayload);

        $duplicate->assertStatus(200)
            ->assertJsonPath('status', 'duplicate_ignored');

        // Only ONE provider_event row (unique event_id constraint worked)
        $this->assertDatabaseCount('provider_events', 1);
    }

    // -----------------------------------------------------------------------
    // TEST 7: An ISSUED request cannot be overwritten by a later callback
    // -----------------------------------------------------------------------

    public function test_issued_state_cannot_be_overwritten(): void
    {
        // Create an already-ISSUED request directly
        $req = PolicyRequest::create(array_merge($this->validPayload(), [
            'premium' => 225.00,
            'status' => PolicyRequest::STATUS_ISSUED,
            'policy_number' => 'POL-ORIGINAL',
        ]));

        // Try to send a FAILED callback to an already-ISSUED request
        $response = $this->postJson('/api/mock-insurer/callback', [
            'event_id' => 'EVT-OVERWRITE-ATTEMPT',
            'event_type' => 'POLICY_FAILED',
            'policy_request_id' => $req->id,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status', 'skipped');

        // Status must still be ISSUED — final state is protected
        $this->assertDatabaseHas('policy_requests', [
            'id' => $req->id,
            'status' => PolicyRequest::STATUS_ISSUED,
        ]);
    }

    // -----------------------------------------------------------------------
    // TEST 8: GET list returns paginated results, filtered by status
    // -----------------------------------------------------------------------

    public function test_list_endpoint_filters_by_status(): void
    {
        PolicyRequest::create(array_merge($this->validPayload(), [
            'client_request_id' => 'REQ-001',
            'premium' => 225.00,
            'status' => PolicyRequest::STATUS_RECEIVED,
        ]));

        PolicyRequest::create(array_merge($this->validPayload(), [
            'client_request_id' => 'REQ-002',
            'premium' => 225.00,
            'status' => PolicyRequest::STATUS_ISSUED,
            'policy_number' => 'POL-XYZ',
        ]));

        $response = $this->getJson('/api/policy-requests?status=ISSUED');
        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('ISSUED', $data[0]['status']);
    }
}
