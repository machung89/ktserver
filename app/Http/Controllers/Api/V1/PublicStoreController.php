<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Promotion;
use App\Models\PushSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cửa hàng công khai — khách truy cập qua token (KHÔNG cần đăng nhập).
 * Xem khuyến mãi đang có + đăng ký nhận Web Push khi có KM mới.
 */
class PublicStoreController extends Controller
{
    private function resolveOrg(string $token): Organization
    {
        return Organization::where('public_token', $token)->where('is_active', true)->firstOrFail();
    }

    public function config(string $token): JsonResponse
    {
        $org = $this->resolveOrg($token);

        return response()->json([
            'shop_name' => $org->name,
            'vapid_public_key' => config('webpush.vapid.public_key'),
        ]);
    }

    public function promotions(string $token): JsonResponse
    {
        $org = $this->resolveOrg($token);
        $today = now()->toDateString();

        $promotions = Promotion::withoutGlobalScopes()
            ->where('organization_id', $org->id)
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('start_date')->orWhere('start_date', '<=', $today))
            ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $today))
            ->orderByDesc('id')
            ->get(['id', 'name', 'type', 'start_date', 'end_date']);

        return response()->json(['shop_name' => $org->name, 'promotions' => $promotions]);
    }

    public function subscribe(string $token, Request $request): JsonResponse
    {
        $org = $this->resolveOrg($token);

        $validated = $request->validate([
            'endpoint' => ['required', 'string'],
            'keys.p256dh' => ['required', 'string'],
            'keys.auth' => ['required', 'string'],
            'contentEncoding' => ['nullable', 'string'],
        ]);

        PushSubscription::updateOrCreate(
            ['organization_id' => $org->id, 'endpoint_hash' => hash('sha256', $validated['endpoint'])],
            [
                'endpoint' => $validated['endpoint'],
                'public_key' => $validated['keys']['p256dh'],
                'auth_token' => $validated['keys']['auth'],
                'content_encoding' => $validated['contentEncoding'] ?? 'aesgcm',
            ],
        );

        return response()->json(['ok' => true]);
    }
}
