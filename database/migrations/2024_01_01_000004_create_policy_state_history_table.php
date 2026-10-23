<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the policy_state_history table.
 *
 * Every time a policy_request changes status, we write a row here.
 * This gives us a complete audit trail: who changed what and when.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_state_history', function (Blueprint $table) {
            $table->id();

            $table->foreignId('policy_request_id')
                  ->constrained()
                  ->cascadeOnDelete();

            $table->string('from_status')->nullable(); // null for the first entry
            $table->string('to_status');
            $table->string('reason')->nullable();      // short note about why

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_state_history');
    }
};
