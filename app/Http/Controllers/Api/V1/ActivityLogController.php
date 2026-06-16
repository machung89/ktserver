<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ScopedByOrganization;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ActivityLogController extends Controller
{
    use ScopedByOrganization;

    public function index(Request $request): JsonResponse
    {
        $hasFilter = $request->filled('tour_id')
            || ($request->filled('subject_type') && $request->filled('subject_id'));

        if (! $hasFilter) {
            /** @var User $user */
            $user = Auth::user();
            abort_unless(
                $user->hasPermission('activity_logs.view_all'),
                403,
                'Chỉ quản trị viên được xem nhật ký toàn bộ.'
            );
        }

        $perPage = (int) ($request->per_page ?: 20);
        $perPage = max(1, min($perPage, 100));

        $logs = ActivityLog::with(['causer', 'tour'])
            ->when($request->tour_id, fn ($q, $v) => $q->where('tour_id', $v))
            ->when(
                $request->filled('subject_type') && $request->filled('subject_id'),
                fn ($q) => $q->where('subject_type', $request->subject_type)
                    ->where('subject_id', $request->subject_id)
            )
            ->latest()
            ->paginate($perPage);

        return response()->json([
            'data' => collect($logs->items())->map(fn ($l) => $this->format($l)),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
                'last_page' => $logs->lastPage(),
            ],
        ]);
    }

    public function storeNote(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tour_id' => ['nullable', 'exists:tours,id'],
            'subject_type' => ['nullable', 'string', 'max:100'],
            'subject_id' => ['nullable', 'integer'],
            'description' => ['required', 'string', 'max:2000'],
        ]);

        $log = ActivityLog::create([
            'organization_id' => $this->orgId(),
            'causer_id' => Auth::id(),
            'tour_id' => $validated['tour_id'] ?? null,
            'subject_type' => $validated['subject_type'] ?? null,
            'subject_id' => $validated['subject_id'] ?? null,
            'action' => 'note',
            'description' => $validated['description'],
        ]);

        return response()->json(['data' => $this->format($log->load('causer'))], 201);
    }

    /** @return array<string, mixed> */
    private function format(ActivityLog $log): array
    {
        return [
            'id' => $log->id,
            'action' => $log->action,
            'description' => $log->description,
            'causer_name' => $log->causer?->name,
            'tour_id' => $log->tour_id,
            'tour_number' => $log->tour?->tour_number,
            'subject_type' => $log->subject_type,
            'subject_id' => $log->subject_id,
            'properties' => $log->properties,
            'created_at' => $log->created_at?->toDateTimeString(),
        ];
    }
}
