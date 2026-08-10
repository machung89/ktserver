<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

class GenerateVapidKeys extends Command
{
    protected $signature = 'webpush:vapid';

    protected $description = 'Sinh cặp VAPID keys cho Web Push (thêm vào .env)';

    public function handle(): int
    {
        $keys = VAPID::createVapidKeys();

        $this->info('Thêm vào .env:');
        $this->line('VAPID_SUBJECT="mailto:admin@your-domain.com"');
        $this->line('VAPID_PUBLIC_KEY="'.$keys['publicKey'].'"');
        $this->line('VAPID_PRIVATE_KEY="'.$keys['privateKey'].'"');

        return self::SUCCESS;
    }
}
