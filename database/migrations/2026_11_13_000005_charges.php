<?php

use App\Services\Fees;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Things staff set in the console rather than in the environment (the charges, to begin with).
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->json('value');
            $table->timestamps();
        });

        // What the bank is expected to keep from a paid payment, worked out when
        // it is paid with the charges in force at that moment — so the figures
        // on old payments do not move when the charges are changed.
        Schema::table('payments', fn (Blueprint $table) => [
            $table->decimal('bank_fee', 12, 2)->nullable()->after('fee'),
            // The gateway's own margin on it: what the reports call gateway profit.
            $table->decimal('margin', 12, 2)->nullable()->after('bank_fee'),
        ]);

        foreach (DB::table('payments')->where('status', 'paid')->get(['id', 'amount', 'fee', 'currency', 'method']) as $p) {
            $bankFee = Fees::bankCut((float) $p->amount + (float) $p->fee, $p->currency, $p->method);
            DB::table('payments')->where('id', $p->id)->update([
                'bank_fee' => $bankFee,
                // No margin was configured before this: anything charged on top beyond the bank's cut counts as margin.
                'margin' => Fees::money(max((float) $p->fee - (float) $bankFee, 0)),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('payments', fn (Blueprint $table) => $table->dropColumn(['bank_fee', 'margin']));
        Schema::dropIfExists('settings');
    }
};
