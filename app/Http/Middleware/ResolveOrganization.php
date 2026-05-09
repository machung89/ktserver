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

        app()->instance('orgId', $user->organization_id);

        return $next($request);
    }
}
