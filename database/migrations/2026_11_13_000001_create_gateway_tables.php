<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A company is a business whose payments run through BlessedPay. Today
        // that is BLESSLUXE's own businesses only (see CLAUDE.md, "option A").
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('website')->nullable();
            $table->string('status')->default('active'); // active | pending | suspended
            $table->text('callback_secret');             // encrypted; signs callbacks to the company
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            // company_id null = BlessedPay staff; set = a member of that company.
            $table->foreignId('company_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->string('role')->default('viewer')->after('password');
            $table->string('status')->default('active')->after('role'); // active | suspended
            $table->timestamp('last_active_at')->nullable();
        });

        Schema::create('invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('role');
            $table->string('token_hash', 64)->unique();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index('email');
        });

        Schema::create('api_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('mode');                   // test | live
            $table->string('prefix', 24);             // shown in the console: sk_test_ab12…
            $table->string('key_hash', 64)->unique(); // sha256 of the full key; the key itself is never stored
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->string('mode');                       // test | live
            $table->string('reference');                  // the company's own reference (order number)
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3);
            $table->string('status')->default('pending'); // pending | paid | failed | cancelled
            $table->string('gateway');                    // sandbox | stewardpay
            $table->string('gateway_reference')->nullable();
            $table->string('method')->nullable();
            $table->string('description')->nullable();
            $table->string('return_url', 2048);
            $table->string('callback_url', 2048)->nullable();
            $table->string('idempotency_key')->nullable();
            $table->json('metadata')->nullable();
            $table->string('callback_status')->nullable(); // delivered | failed
            $table->unsignedSmallInteger('callback_attempts')->default(0);
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'mode', 'idempotency_key']);
            $table->index(['company_id', 'created_at']);
            $table->index(['company_id', 'reference']);
            $table->index(['status', 'created_at']);
        });

        // The payment log: everything that happened to a payment, in order.
        Schema::create('payment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('payment_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('summary');
            $table->json('data')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['payment_id', 'id']);
        });

        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('actor_name');
            $table->string('action');
            $table->string('summary');
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['company_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('payment_events');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('api_keys');
        Schema::dropIfExists('invitations');
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
            $table->dropColumn(['role', 'status', 'last_active_at']);
        });
        Schema::dropIfExists('companies');
    }
};
