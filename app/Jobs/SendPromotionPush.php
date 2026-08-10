<?php

namespace App\Jobs;

use App\Models\Organization;
use App\Services\WebPushService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SendPromotionPush implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $organizationId,
        public string $title,
        public string $body,
        public ?string $url = null,
    ) {}

    public function handle(WebPushService $webPush): void
    {
        $org = Organization::find($this->organizationId);
        if ($org) {
            $webPush->sendToOrganization($org, $this->title, $this->body, $this->url);
        }
    }
}
