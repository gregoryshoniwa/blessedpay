<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'company_id', 'mode', 'reference', 'amount', 'fee', 'bank_fee', 'margin', 'payer_phone', 'expires_at', 'currency', 'status', 'gateway', 'gateway_reference',
    'method', 'description', 'return_url', 'callback_url', 'idempotency_key', 'metadata',
    'callback_status', 'callback_attempts', 'paid_at', 'finished_at',
])]
class Payment extends Model
{
    use HasUlids;

    public const FINAL = ['paid', 'failed', 'cancelled'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'fee' => 'decimal:2',
            'bank_fee' => 'decimal:2',
            'margin' => 'decimal:2',
            'expires_at' => 'datetime',
            'metadata' => 'array',
            'paid_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(PaymentEvent::class)->orderBy('id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(PaymentAttempt::class)->orderBy('id');
    }

    /** The try that is in progress right now, if any. */
    public function openAttempt(): ?PaymentAttempt
    {
        return $this->attempts()->where('status', 'pending')->reorder()->latest('id')->first();
    }

    /** What the shopper is actually charged: the amount, plus the fee when they bear it. */
    public function charged(): string
    {
        return number_format((float) $this->amount + (float) $this->fee, 2, '.', '');
    }

    public function isFinal(): bool
    {
        return in_array($this->status, self::FINAL, true);
    }

    /** The id as the outside world sees it: pay_01J… */
    public function publicId(): string
    {
        return 'pay_'.$this->id;
    }

    public static function findPublic(string $publicId): ?self
    {
        return self::find(str_starts_with($publicId, 'pay_') ? substr($publicId, 4) : $publicId);
    }

    /** Add a step to the audit trail (see PaymentEvent for actor and level). */
    public function log(
        string $type,
        string $summary,
        ?array $data = null,
        string $actor = 'system',
        string $level = 'info',
        ?PaymentAttempt $attempt = null,
        ?int $ms = null,
    ): PaymentEvent {
        return $this->events()->create([
            'type' => $type,
            'actor' => $actor,
            'level' => $level,
            'attempt_id' => $attempt?->id,
            'duration_ms' => $ms,
            'summary' => mb_substr($summary, 0, 255),
            'data' => $data,
        ]);
    }

    /**
     * The same question got the same answer again: count it on the line it
     * repeats (the latest of that type, for that try if one is given).
     */
    public function logRepeat(string $type, ?PaymentAttempt $attempt = null): bool
    {
        $last = $this->events()->where('type', $type)
            ->when($attempt, fn ($q) => $q->where('attempt_id', $attempt->id))
            ->reorder()->latest('id')->first();

        if (! $last) {
            return false;
        }

        $last->forceFill(['data' => ['repeats' => ($last->data['repeats'] ?? 1) + 1, 'last_asked' => now()->toIso8601String()] + ($last->data ?? [])])->save();

        return true;
    }

    /** What the API and callbacks say about a payment. Never includes secrets. */
    public function toApi(): array
    {
        return [
            'id' => $this->publicId(),
            'status' => $this->status,
            'mode' => $this->mode,
            'amount' => $this->amount,
            'fee' => $this->fee,
            'charged' => $this->charged(),
            'currency' => $this->currency,
            'reference' => $this->reference,
            'description' => $this->description,
            'method' => $this->method,
            'gateway_reference' => $this->gateway_reference,
            'metadata' => $this->metadata,
            'redirect_url' => $this->isFinal() ? null : route('pay.show', $this->publicId()),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
