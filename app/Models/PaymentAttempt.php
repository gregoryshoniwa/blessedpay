<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One try at paying a payment with one method. */
#[Fillable(['payment_id', 'method', 'status', 'reference', 'gateway_uid', 'phone', 'amount', 'data', 'error', 'checked_at'])]
class PaymentAttempt extends Model
{
    public const METHODS = ['card' => 'Card', 'ecocash' => 'EcoCash', 'bank' => 'TN CyberTech Bank account'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'data' => 'array', 'checked_at' => 'datetime'];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function label(): string
    {
        return self::METHODS[$this->method] ?? $this->method;
    }
}
