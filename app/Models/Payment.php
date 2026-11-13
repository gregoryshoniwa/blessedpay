<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'company_id', 'mode', 'reference', 'amount', 'currency', 'status', 'gateway', 'gateway_reference',
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

    public function log(string $type, string $summary, ?array $data = null): PaymentEvent
    {
        return $this->events()->create(['type' => $type, 'summary' => $summary, 'data' => $data]);
    }

    /** What the API and callbacks say about a payment. Never includes secrets. */
    public function toApi(): array
    {
        return [
            'id' => $this->publicId(),
            'status' => $this->status,
            'mode' => $this->mode,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'reference' => $this->reference,
            'description' => $this->description,
            'method' => $this->method,
            'gateway_reference' => $this->gateway_reference,
            'metadata' => $this->metadata,
            'redirect_url' => $this->isFinal() ? null : route('pay.show', $this->publicId()),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
