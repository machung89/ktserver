<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class LockedAccountTest extends TestCase
{
    #[TestDox('Tài khoản bị khóa không truy cập được dù token cũ vẫn còn')]
    public function test_locked_account_is_blocked_on_every_request(): void
    {
        // Còn hoạt động → truy cập bình thường
        $this->getJson('/api/v1/products')->assertOk();

        // Admin khóa tài khoản (is_active = false)
        $this->user->update(['is_active' => false]);

        // Token cũ vẫn còn nhưng phải bị chặn ngay
        $this->getJson('/api/v1/products')->assertForbidden();
    }
}
