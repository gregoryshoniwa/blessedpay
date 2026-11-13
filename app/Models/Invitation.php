<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['company_id', 'email', 'role', 'token_hash', 'invited_by', 'expires_at', 'accepted_at', 'revoked_at'])]
#[Hidden(['token_hash'])]
class Invitation extends Model
{
    public const DAYS_VALID = 7;

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'accepted_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function isOpen(): bool
    {
        return $this->accepted_at === null && $this->revoked_at === null && $this->expires_at->isFuture();
    }

    /** Give the invitation a fresh single-use token and expiry; returns the plain token. */
    public function refreshToken(): string
    {
        $plain = bin2hex(random_bytes(32));
        $this->forceFill([
            'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->addDays(self::DAYS_VALID),
        ])->save();

        return $plain;
    }

    public static function findByToken(string $plain): ?self
    {
        return self::where('token_hash', hash('sha256', $plain))->first();
    }
}
