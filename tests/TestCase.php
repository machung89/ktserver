<?php

namespace Tests;

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        // Guard: dừng ngay nếu đang chạy trên database production
        $dbName = DB::connection()->getDatabaseName();
        if ($dbName !== 'ktsever_test') {
            throw new \RuntimeException(
                "NGUY HIỂM: Tests đang chạy trên database '{$dbName}' thay vì 'ktsever_test'.\n".
                'Chạy lại với: php artisan test --env=testing'
            );
        }

        $this->organization = Organization::create([
            'name' => 'Test Org',
            'is_active' => true,
        ]);

        $adminRole = Role::create([
            'organization_id' => $this->organization->id,
            'name' => 'admin',
            'display_name' => 'Admin',
            'is_system' => true,
        ]);

        $this->user = User::factory()->create([
            'organization_id' => $this->organization->id,
            'is_active' => true,
        ]);

        $this->user->roles()->attach($adminRole->id);

        $this->actingAs($this->user, 'sanctum');
    }
}
