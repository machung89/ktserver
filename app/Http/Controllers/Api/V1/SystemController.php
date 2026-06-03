<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class SystemController extends Controller
{
    /**
     * Danh sách tất cả tổ chức (chỉ super admin).
     */
    public function organizations(Request $request): JsonResponse
    {
        $userCounts = DB::table('users')
            ->select('organization_id', DB::raw('COUNT(*) as cnt'))
            ->groupBy('organization_id')
            ->pluck('cnt', 'organization_id');

        $orgs = Organization::query()
            ->when($request->search, fn ($q, $v) => $q->where('name', 'like', "%{$v}%")->orWhere('tax_code', 'like', "%{$v}%"))
            ->orderBy('id')
            ->get()
            ->map(function (Organization $o) use ($userCounts) {
                $endsAt = $o->subscription_ends_at;
                $daysLeft = $endsAt ? now()->startOfDay()->diffInDays($endsAt, false) : null;

                return [
                    'id' => $o->id,
                    'name' => $o->name,
                    'tax_code' => $o->tax_code,
                    'phone' => $o->phone,
                    'email' => $o->email,
                    'is_active' => (bool) $o->is_active,
                    'user_count' => (int) ($userCounts[$o->id] ?? 0),
                    'subscription_ends_at' => $endsAt?->toDateString(),
                    'days_left' => $daysLeft !== null ? (int) $daysLeft : null,
                    'is_expired' => $endsAt !== null && $daysLeft < 0,
                    'created_at' => $o->created_at?->toDateString(),
                ];
            });

        return response()->json(['data' => $orgs]);
    }

    /**
     * Gia hạn sử dụng: set ngày hết hạn mới hoặc cộng thêm số tháng.
     */
    public function extendSubscription(Request $request, Organization $organization): JsonResponse
    {
        $validated = $request->validate([
            'months' => ['nullable', 'integer', 'min:1', 'max:120'],
            'subscription_ends_at' => ['nullable', 'date'],
        ]);

        if (! empty($validated['subscription_ends_at'])) {
            $newDate = Carbon::parse($validated['subscription_ends_at']);
        } else {
            $months = (int) ($validated['months'] ?? 1);
            // Cộng từ ngày hết hạn hiện tại nếu còn hạn, ngược lại cộng từ hôm nay
            $base = $organization->subscription_ends_at && $organization->subscription_ends_at->isFuture()
                ? $organization->subscription_ends_at
                : now();
            $newDate = $base->copy()->addMonths($months);
        }

        $organization->update(['subscription_ends_at' => $newDate->toDateString()]);

        return response()->json([
            'data' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'subscription_ends_at' => $newDate->toDateString(),
            ],
        ]);
    }

    /**
     * Bật/tắt hoạt động tổ chức.
     */
    public function toggleActive(Organization $organization): JsonResponse
    {
        $organization->update(['is_active' => ! $organization->is_active]);

        return response()->json(['data' => ['id' => $organization->id, 'is_active' => $organization->is_active]]);
    }

    /**
     * Chuyển ngữ cảnh: đổi organization_id của super admin sang tổ chức đích để truy cập dữ liệu của họ.
     */
    public function switchOrganization(Request $request, Organization $organization): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $user->update(['organization_id' => $organization->id]);

        return response()->json([
            'message' => "Đã chuyển sang tổ chức: {$organization->name}",
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'tax_code' => $organization->tax_code,
            ],
        ]);
    }
}
