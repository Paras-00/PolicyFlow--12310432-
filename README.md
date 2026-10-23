# PolicyFlow — TripperShield Campus Assignment

A fault-tolerant insurance policy issuance API built with Laravel 12 and PHP 8.2.

---

## Quick Setup

### 1. Install dependencies
```bash
cd policyflow
composer install
```

### 2. Configure environment
```bash
cp .env.example .env
php artisan key:generate
```

Edit `.env` and set your database credentials (MySQL or use SQLite for local dev):
```
DB_CONNECTION=mysql
DB_DATABASE=policyflow
DB_USERNAME=root
DB_PASSWORD=
```

> For SQLite (zero config): set `DB_CONNECTION=sqlite` and create `database/database.sqlite`

### 3. Run migrations
```bash
php artisan migrate
```

### 4. Start the server
```bash
php artisan serve
```

### 5. Run tests
```bash
php artisan test
```

---

## Architecture

```
HTTP Request
    │
    ▼
Controller  (thin — validates, calls service, returns JSON)
    │
    ▼
Service     (all business logic, DB transactions, state machine)
    │
    ├── PremiumCalculator   (pure pricing rules — no DB)
    ├── PolicyIssuanceService  (orchestrates full lifecycle)
    └── MockInsurerService  (simulates external provider)
    │
    ▼
Models → Database (4 tables)
```

### Tables
| Table | Purpose |
|---|---|
| `policy_requests` | One record per unique agent+request |
| `issuance_attempts` | Log of every provider call |
| `provider_events` | Inbound callbacks (idempotency via unique event_id) |
| `policy_state_history` | Full audit trail of status changes |

### Status Flow
```
RECEIVED → VALIDATED → SUBMITTED → PENDING_CONFIRMATION → ISSUED
                                                        → FAILED
                                                        → NEEDS_RECONCILIATION
```

---

## API Reference

### Create Policy Request
```bash
POST /api/policy-requests
Content-Type: application/json

{
  "client_request_id": "AGT-2026-0001",
  "agent_id": "AGENT-17",
  "customer_name": "Sample Customer",
  "date_of_birth": "1998-04-10",
  "destination": "INTERNATIONAL",
  "trip_start": "2026-11-10",
  "trip_end": "2026-11-14",
  "plan_code": "PLUS",
  "sum_insured": 300000
}

# 201 → new request created, premium = ₹225.00
# 200 → duplicate — existing request returned safely
# 422 → validation error with field-level details
```

### Get Single Request (with audit trail)
```bash
GET /api/policy-requests/{id}
```

### List Requests
```bash
GET /api/policy-requests?status=ISSUED&per_page=10
```

### Submit to Insurer
```bash
POST /api/policy-requests/{id}/submit
```

### Simulate Insurer Callback
```bash
POST /api/mock-insurer/callback
{
  "event_id": "EVT-001",
  "event_type": "POLICY_ISSUED",
  "policy_request_id": 1,
  "policy_number": "POL-XYZ123"
}
```

---

## Premium Calculation Example

> PLUS plan, 5 days, age 28, INTERNATIONAL, ₹3,00,000 coverage

```
₹20 × 5 days × 1.00 (age) × 1.80 (international) × 1.25 (coverage)
= ₹225.00
```

---

## Reliability Scenarios Implemented

### 1. Duplicate Intake Prevention
- **How**: `UNIQUE(agent_id, client_request_id)` DB constraint
- **Effect**: Repeated submissions return 200 with the existing record. No duplicate records created.
- **Test**: `test_duplicate_intake_returns_200_not_201`

### 2. Duplicate Callback Prevention
- **How**: `UNIQUE(event_id)` on `provider_events` table
- **Effect**: If the same callback event arrives twice, the second insert fails at DB level and is safely ignored.
- **Test**: `test_duplicate_callback_is_safely_ignored`

### Other Scenarios (not implemented, explained):
- **Timeout After Issue**: Would use `NEEDS_RECONCILIATION` status + a reconciliation command to query provider
- **Retry After Failure**: Would re-submit only if status is `FAILED`, reusing the already-calculated (immutable) premium

---

## Known Limitations

- No authentication / API keys (out of scope per assignment)
- Mock insurer is an in-process simulation, not a real HTTP service
- No queue-based retry (would use Laravel Queues in production)
- CSV import is not implemented (chose duplicate prevention as the optional showcase)
