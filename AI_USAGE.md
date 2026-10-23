# AI Usage Disclosure — PolicyFlow Assignment

## Tools Used
- **Google Gemini (Antigravity)** — Used as a pair-programming assistant throughout the build

---

## How AI Helped

1. **Scaffolding boilerplate** — Generated the initial migration schemas and Eloquent model structure, which I then reviewed and adjusted to match the exact assignment requirements.

2. **Suggesting the FormRequest pattern** — AI recommended extracting validation into a dedicated `StorePolicyRequest` class instead of validating inside the controller. This keeps the controller thin and is a Laravel best practice.

3. **Planning the reliability strategy** — AI helped me think through which two reliability cases (duplicate intake vs. duplicate callback) were the most demonstrable with simple DB-level constraints, avoiding over-engineering.

---

## Where AI Was Wrong / Incomplete

- AI initially suggested using `try/catch` on the entire controller action for duplicate detection. This was incorrect — the correct approach is to check for existing records *before* the insert, avoiding relying purely on exception handling for business logic.
- AI suggested Redis-based locking for idempotency. I chose a simpler DB-level `UNIQUE` constraint instead, which is more appropriate for this assignment scope and easier to test and explain.

---

## How I Verified the Output

- Ran `php artisan test` to verify all 8 feature tests pass
- Manually tested each API endpoint with curl commands
- Reviewed every generated file line by line and added explanatory comments
- Confirmed the premium calculation: ₹20 × 5 × 1.00 × 1.80 × 1.25 = ₹225.00 ✓
