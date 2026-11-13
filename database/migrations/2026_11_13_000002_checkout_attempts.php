<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // Who pays the bank's processing fee: the company (taken out of what
            // it receives) or the shopper (added on top at the checkout).
            $table->string('fee_bearer')->default('merchant')->after('status'); // merchant | customer
            // Extra sites allowed to show the checkout in a pop-up. The company's
            // website and each payment's return address are always allowed.
            $table->json('embed_origins')->nullable()->after('fee_bearer');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->decimal('fee', 12, 2)->default(0)->after('amount');      // what the shopper pays on top (0 when the company absorbs it)
            $table->string('payer_phone', 20)->nullable()->after('method');
            $table->timestamp('expires_at')->nullable()->after('finished_at');
        });

        // One try at paying: a payment can be tried several times (a declined
        // card, then EcoCash) but is paid at most once.
        Schema::create('payment_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('payment_id')->constrained()->cascadeOnDelete();
            $table->string('method');                     // card | ecocash | bank
            $table->string('status')->default('pending'); // pending | paid | failed | abandoned
            $table->string('reference')->unique();        // sent to the bank as billerReference; unique per try
            $table->string('gateway_uid')->nullable()->index(); // the bank's id for this try
            $table->string('phone', 20)->nullable();
            $table->decimal('amount', 12, 2);             // what the bank was asked to charge
            $table->json('data')->nullable();             // what the checkout needs next (card session, bank page)
            $table->string('error')->nullable();
            $table->timestamp('checked_at')->nullable();  // last time we asked the bank
            $table->timestamps();
            $table->index(['payment_id', 'id']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_attempts');
        Schema::table('payments', fn (Blueprint $table) => $table->dropColumn(['fee', 'payer_phone', 'expires_at']));
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn(['fee_bearer', 'embed_origins']));
    }
};
