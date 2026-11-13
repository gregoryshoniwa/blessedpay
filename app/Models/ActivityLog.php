<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/** Who did what in the console. Append-only. */
#[Fillable(['user_id', 'company_id', 'actor_name', 'action', 'summary', 'ip'])]
class ActivityLog extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public static function record(Request $request, string $action, string $summary, ?int $companyId = null, ?User $actor = null): void
    {
        $actor ??= $request->user();

        self::create([
            'user_id' => $actor?->id,
            'company_id' => $companyId,
            'actor_name' => $actor?->name ?? 'System',
            'action' => $action,
            'summary' => $summary,
            'ip' => $request->ip(),
        ]);
    }
}
