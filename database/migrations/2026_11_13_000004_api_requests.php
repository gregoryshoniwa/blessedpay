<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every call a company's system makes to /api/v1, with what it sent and
     * what we answered in full — including the calls that were refused and
     * never became a payment (wrong key, missing field), which is where an
     * integration usually goes wrong.
     */
    public function up(): void
    {
        Schema::create('api_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete(); // null: the key was not recognised
            $table->string('mode', 8)->nullable();        // test | live, from the key
            $table->foreignUlid('payment_id')->nullable()->index();
            $table->string('method', 8);
            $table->string('path');
            $table->unsignedSmallInteger('status');
            $table->string('key_hint', 24)->nullable();   // the start of the key used, never the whole key
            $table->string('ip', 45)->nullable();
            $table->json('request')->nullable();          // {headers, body}
            $table->json('response')->nullable();         // the body we answered with
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedInteger('repeats')->default(1); // identical status reads are counted, not repeated
            $table->timestamp('last_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['company_id', 'mode', 'id']);
            $table->index(['mode', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_requests');
    }
};
