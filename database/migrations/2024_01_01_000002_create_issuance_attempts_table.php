<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the issuance_attempts table.
 *
 * Every time we call the mock insurer, we log it here.
 * This lets us see HOW MANY TIMES we tried, and what responses we got.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issuance_attempts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('policy_request_id')
                  ->constrained()
                  ->cascadeOnDelete();

            $table->unsignedInteger('attempt_number'); // 1, 2, 3 ...
            $table->string('status');                   // SUCCESS, FAILED, TIMEOUT, etc.
            $table->text('provider_response')->nullable(); // raw JSON response
            $table->string('error_message')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issuance_attempts');
    }
};
