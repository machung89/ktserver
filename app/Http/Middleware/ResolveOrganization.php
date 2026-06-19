<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveOrganization
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->organization_id) {
            return response()->json(['message' => 'Tài khoản chưa gắn với công ty nào'], 403);
        }

        // Chặn ngay tài khoản bị khóa, kể cả khi token cũ vẫn còn hiệu lực
        if (! $user->is_active) {
            return response()->json(['message' => 'Tài khoản đã bị khóa.'], 403);
        }

        app()->instance('orgId', $user->organization_id);

        return $next($request);
    }
}
