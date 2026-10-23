<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the main policy_requests table.
 *
 * This table stores one record per unique (agent_id + client_request_id) pair.
 * The unique index is the KEY mechanism that prevents duplicate policy requests.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_requests', function (Blueprint $table) {
            $table->id();

            // --- Who sent this request ---
            $table->string('client_request_id');   // agent's own reference number
            $table->string('agent_id');             // which agent submitted it

            // --- Customer info ---
            $table->string('customer_name');
            $table->date('date_of_birth');

            // --- Trip details ---
            $table->enum('destination', ['DOMESTIC', 'INTERNATIONAL']);
            $table->date('trip_start');
            $table->date('trip_end');

            // --- Plan details ---
            $table->enum('plan_code', ['BASIC', 'PLUS', 'PREMIUM']);
            $table->unsignedInteger('sum_insured'); // e.g. 100000, 300000, 500000

            // --- Calculated values (set once, never changed) ---
            $table->decimal('premium', 10, 2)->nullable(); // e.g. 225.00

            // --- Current workflow state ---
            $table->string('status')->default('RECEIVED');
            // RECEIVED → VALIDATED → SUBMITTED → PENDING_CONFIRMATION → ISSUED / FAILED

            // --- Issued policy info (set only when ISSUED) ---
            $table->string('policy_number')->nullable()->unique();
            $table->string('provider_reference')->nullable();

            $table->timestamps();

            // DUPLICATE PREVENTION: DB-level unique constraint
            // If the same agent submits the same client_request_id again, DB blocks it.
            $table->unique(['agent_id', 'client_request_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_requests');
    }
};
