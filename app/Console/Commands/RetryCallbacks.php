<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\Services\CallbackSender;
use App\Services\PaymentService;
use Illuminate\Console\Command;

/**
 * No queue worker or scheduler runs in production, so this is the catch-up:
 * re-send callbacks that failed, and ask the bank about payments still pending.
 * Safe to run as often as you like.
 */
class RetryCallbacks extends Command
{
    protected $signature = 'payments:callbacks';

    protected $description = 'Retry failed callbacks and re-check pending payments';

    public function handle(CallbackSender $callbacks, PaymentService $payments): int
    {
        $settled = 0;
        Payment::where('status', 'pending')->where('created_at', '>=', now()->subDays(3))->oldest('created_at')
            ->each(function (Payment $payment) use ($payments, &$settled) {
                $settled += $payments->refresh($payment)->isFinal() ? 1 : 0;
            });

        $sent = $failed = 0;
        Payment::with('company')->where('callback_status', 'failed')->where('callback_attempts', '<', CallbackSender::MAX_ATTEMPTS)
            ->oldest('finished_at')
            ->each(function (Payment $payment) use ($callbacks, &$sent, &$failed) {
                $callbacks->send($payment) ? $sent++ : $failed++;
            });

        $this->info("Pending payments settled: {$settled}. Callbacks delivered: {$sent}. Still failing: {$failed}.");

        return self::SUCCESS;
    }
}
