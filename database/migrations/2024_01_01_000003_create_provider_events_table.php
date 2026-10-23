<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the provider_events table.
 *
 * When the mock insurer sends a callback (e.g. "your policy is now ISSUED"),
 * we store it here. The unique event_id prevents processing the same callback twice.
 *
 * RELIABILITY CASE 2: Duplicate callback prevention.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('policy_request_id')
                  ->constrained()
                  ->cascadeOnDelete();

            // The insurer sends a unique ID with each event.
            // DB-level uniqueness means even if the same event arrives twice,
            // only the first insert succeeds. The second is safely ignored.
            $table->string('event_id')->unique();

            $table->string('event_type');   // e.g. POLICY_ISSUED, POLICY_FAILED
            $table->text('payload');        // full JSON from insurer
            $table->boolean('processed')->default(false);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_events');
    }
};
