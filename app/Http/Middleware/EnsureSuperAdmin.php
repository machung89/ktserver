<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSuperAdmin
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user?->is_super_admin) {
            return response()->json(['message' => 'Chỉ quản trị hệ thống mới được truy cập.'], 403);
        }

        if (! $user->is_active) {
            return response()->json(['message' => 'Tài khoản đã bị khóa.'], 403);
        }

        return $next($request);
    }
}
