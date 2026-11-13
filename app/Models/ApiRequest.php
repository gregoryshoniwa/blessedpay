<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One call to /api/v1 from a company's system: what it sent, what we answered. */
#[Fillable(['company_id', 'mode', 'payment_id', 'method', 'path', 'status', 'key_hint', 'ip', 'request', 'response', 'duration_ms', 'repeats', 'last_at'])]
class ApiRequest extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['request' => 'array', 'response' => 'array', 'created_at' => 'datetime', 'last_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
