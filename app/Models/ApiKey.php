<?php

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

#[Fillable(['company_id', 'name', 'mode', 'prefix', 'key_hash', 'created_by', 'last_used_at', 'revoked_at'])]
#[Hidden(['key_hash'])]
class ApiKey extends Model
{
    protected function casts(): array
    {
        return ['last_used_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Make a key. The plain key is returned once and never stored — only its
     * hash is, so a database leak does not leak working keys.
     *
     * @return array{0: self, 1: string} [the saved row, the plain key]
     */
    public static function issue(Company $company, string $name, string $mode, ?int $createdBy = null): array
    {
        $plain = "sk_{$mode}_".bin2hex(random_bytes(24));

        $key = self::create([
            'company_id' => $company->id,
            'name' => $name,
            'mode' => $mode,
            'prefix' => substr($plain, 0, 14),
            'key_hash' => hash('sha256', $plain),
            'created_by' => $createdBy,
        ]);

        return [$key, $plain];
    }

    public static function findByPlain(string $plain): ?self
    {
        if (str_starts_with($plain, self::PASS_PREFIX)) {
            return self::findByPass($plain);
        }

        return self::where('key_hash', hash('sha256', $plain))->whereNull('revoked_at')->first();
    }

    public const PASS_PREFIX = 'bpt_';

    public const PASS_MINUTES = 30;

    /**
     * A short-lived pass that stands in for this key. A key's secret is only
     * ever shown once, so the console's "Try it" page cannot be handed an
     * existing key — it is handed one of these instead, good for half an hour,
     * and only to someone who could have created a key anyway.
     */
    public function pass(): string
    {
        return self::PASS_PREFIX.Crypt::encryptString(json_encode(['k' => $this->id, 'e' => now()->addMinutes(self::PASS_MINUTES)->timestamp]));
    }

    private static function findByPass(string $pass): ?self
    {
        try {
            $data = json_decode(Crypt::decryptString(substr($pass, strlen(self::PASS_PREFIX))), true);
        } catch (DecryptException) {
            return null;
        }

        if (! is_array($data) || ($data['e'] ?? 0) < now()->timestamp) {
            return null;
        }

        return self::whereKey($data['k'] ?? 0)->whereNull('revoked_at')->first(); // a revoked key's passes die with it
    }
}
