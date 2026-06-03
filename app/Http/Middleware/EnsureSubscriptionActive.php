<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSubscriptionActive
{
    /**
     * Chặn tạo mới giao dịch khi tổ chức đã hết hạn sử dụng.
     * Super admin được bỏ qua để hỗ trợ.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->is_super_admin) {
            return $next($request);
        }

        $org = Organization::find(app('orgId'));
        if ($org && $org->subscription_ends_at && $org->subscription_ends_at->lt(now()->startOfDay())) {
            return response()->json([
                'message' => 'Phần mềm đã hết hạn sử dụng. Vui lòng liên hệ nhà cung cấp để gia hạn trước khi tạo giao dịch mới.',
            ], 403);
        }

        return $next($request);
    }
}
