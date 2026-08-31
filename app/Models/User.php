<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Scopes\OwnedByOrganization;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'employee_code', 'email', 'password', 'phone', 'department', 'position', 'is_active', 'is_super_admin', 'must_change_password', 'organization_id'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected static function booted(): void
    {
        static::addGlobalScope(new OwnedByOrganization);
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'is_super_admin' => 'boolean',
            'must_change_password' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    public function viewableUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_viewable_users', 'user_id', 'viewable_user_id');
    }

    /** @return array<int> */
    public function getViewableUserIds(): array
    {
        return $this->viewableUsers()->pluck('users.id')->all();
    }

    public function hasPermission(string $permission): bool
    {
        // Super admin (quản trị hệ thống) có toàn quyền, kể cả khi đã switch sang org khác
        // (roles bị scope theo org nên sẽ rỗng ở org không phải org gốc).
        if ($this->is_super_admin) {
            return true;
        }

        if ($this->roles()->where('name', 'admin')->exists()) {
            return true;
        }

        return $this->roles()
            ->whereHas('permissions', fn ($q) => $q->where('name', $permission))
            ->exists();
    }
}
