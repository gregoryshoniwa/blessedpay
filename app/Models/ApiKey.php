<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        return self::where('key_hash', hash('sha256', $plain))->whereNull('revoked_at')->first();
    }
}
