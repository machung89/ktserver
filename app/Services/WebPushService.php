<?php

namespace App\Services;

use App\Models\Organization;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

class WebPushService
{
    public function configured(): bool
    {
        return ! empty(config('webpush.vapid.public_key'))
            && ! empty(config('webpush.vapid.private_key'));
    }

    /**
     * Gửi Web Push tới mọi thiết bị đã đăng ký của tổ chức.
     * Tự xóa subscription đã hết hạn (404/410). No-op nếu chưa cấu hình VAPID.
     *
     * @return int số thông báo gửi thành công
     */
    public function sendToOrganization(Organization $org, string $title, string $body, ?string $url = null): int
    {
        if (! $this->configured()) {
            return 0;
        }

        $subs = $org->pushSubscriptions()->get();
        if ($subs->isEmpty()) {
            return 0;
        }

        $webPush = new WebPush(['VAPID' => [
            'subject' => config('webpush.vapid.subject'),
            'publicKey' => config('webpush.vapid.public_key'),
            'privateKey' => config('webpush.vapid.private_key'),
        ]]);

        $payload = json_encode(['title' => $title, 'body' => $body, 'url' => $url]);

        $byEndpoint = [];
        foreach ($subs as $s) {
            $byEndpoint[$s->endpoint] = $s;
            $webPush->queueNotification(
                Subscription::create([
                    'endpoint' => $s->endpoint,
                    'publicKey' => $s->public_key,
                    'authToken' => $s->auth_token,
                    'contentEncoding' => $s->content_encoding,
                ]),
                $payload,
            );
        }

        $sent = 0;
        foreach ($webPush->flush() as $report) {
            $endpoint = $report->getEndpoint();
            if ($report->isSuccess()) {
                $sent++;
            } elseif ($report->isSubscriptionExpired() && isset($byEndpoint[$endpoint])) {
                $byEndpoint[$endpoint]->delete();
            }
        }

        return $sent;
    }
}
