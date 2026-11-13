<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One step in a payment's audit trail. Append-only, with one exception: asking
 * the bank the same question and getting the same answer again is counted on
 * the line it repeats rather than written hundreds of times.
 *
 * actor — whose step it was: merchant (the company's system), shopper, bank,
 *         system (BlessedPay) or staff.
 * level — how it went: info, ok, warn or error. An `error` line's actor is
 *         where the payment was stopped.
 */
#[Fillable(['payment_id', 'type', 'actor', 'level', 'attempt_id', 'duration_ms', 'summary', 'data'])]
class PaymentEvent extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['data' => 'array', 'created_at' => 'datetime'];
    }
}
