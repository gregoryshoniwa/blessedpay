<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivityController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $this->need($request, 'activity.view');
        $companyId = $this->companyFilter($request);

        $page = ActivityLog::query()
            // A member sees their company's log; staff see everything, or one company.
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))
            ->when(! $user->isStaff(), fn ($q) => $q->whereNotNull('company_id'))
            ->latest('id')
            ->paginate(40);

        return response()->json([
            'entries' => collect($page->items())->map(fn (ActivityLog $a) => [
                'id' => $a->id,
                'actor' => $a->actor_name,
                'action' => $a->action,
                'summary' => $a->summary,
                'at' => $a->created_at?->toIso8601String(),
            ]),
            'page' => $page->currentPage(),
            'pages' => $page->lastPage(),
        ]);
    }
}
