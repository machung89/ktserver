<?php

return [
    // VAPID: danh tính server để gửi Web Push (tự sinh, KHÔNG cần bên thứ 3).
    // Sinh keys: php artisan webpush:vapid
    'vapid' => [
        'subject' => env('VAPID_SUBJECT', env('APP_URL', 'https://localhost')),
        'public_key' => env('VAPID_PUBLIC_KEY', ''),
        'private_key' => env('VAPID_PRIVATE_KEY', ''),
    ],
];
