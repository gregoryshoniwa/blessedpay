<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['name', 'slug', 'website', 'status', 'callback_secret', 'fee_bearer', 'embed_origins'])]
#[Hidden(['callback_secret'])]
class Company extends Model
{
    protected function casts(): array
    {
        return ['callback_secret' => 'encrypted', 'embed_origins' => 'array'];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * active    — approved: test and live keys work.
     * pending   — signed itself up and is waiting for BlessedPay staff: its
     *             people can sign in and use TEST keys only.
     * suspended — nothing works.
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }

    /** A URL-safe handle that no other company has. */
    public static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'company';
        $slug = $base;
        for ($i = 2; self::where('slug', $slug)->exists(); $i++) {
            $slug = $base.'-'.$i;
        }

        return $slug;
    }

    public static function newSecret(): string
    {
        return 'whsec_'.bin2hex(random_bytes(24));
    }
}
