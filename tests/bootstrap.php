<?php

// Xóa config cache trước khi chạy test để tránh dùng nhầm DB production
$cacheFile = __DIR__.'/../bootstrap/cache/config.php';
if (file_exists($cacheFile)) {
    unlink($cacheFile);
}

require __DIR__.'/../vendor/autoload.php';
