<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The payment log becomes a full audit trail: every step says WHO it
     * belongs to and HOW it went, so a failed payment shows at a glance whether
     * the bank, the company's system, the shopper or BlessedPay stopped it.
     */
    public function up(): void
    {
        Schema::table('payment_events', function (Blueprint $table) {
            $table->string('actor', 12)->default('system')->after('type'); // merchant | shopper | system | bank | staff
            $table->string('level', 8)->default('info')->after('actor');   // info | ok | warn | error
            $table->unsignedBigInteger('attempt_id')->nullable()->after('level'); // the try this step belongs to
            $table->unsignedInteger('duration_ms')->nullable()->after('attempt_id'); // how long an outside call took
        });

        // Lists are filtered by company, mode and date.
        Schema::table('payments', fn (Blueprint $table) => $table->index(['company_id', 'mode', 'created_at'], 'payments_list_index'));

        // Label the lines written before this change.
        foreach ([
            'payment.created' => ['merchant', 'info'],
            'attempt.started' => ['shopper', 'info'],
            'attempt.failed' => ['bank', 'error'],
            'attempt.paid_twice' => ['system', 'error'],
            'bank.callback' => ['bank', 'info'],
            'payment.paid' => ['system', 'ok'],
            'payment.failed' => ['system', 'error'],
            'payment.cancelled' => ['shopper', 'warn'],
            'callback.delivered' => ['merchant', 'ok'],
            'callback.failed' => ['merchant', 'error'],
            'status.ignored' => ['system', 'warn'],
            'status.corrected' => ['system', 'warn'],
        ] as $type => [$actor, $level]) {
            DB::table('payment_events')->where('type', $type)->update(['actor' => $actor, 'level' => $level]);
        }
    }

    public function down(): void
    {
        Schema::table('payments', fn (Blueprint $table) => $table->dropIndex('payments_list_index'));
        Schema::table('payment_events', fn (Blueprint $table) => $table->dropColumn(['actor', 'level', 'attempt_id', 'duration_ms']));
    }
};
