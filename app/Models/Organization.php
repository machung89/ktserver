<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'tax_code', 'address', 'phone', 'email', 'website', 'logo_url', 'print_template', 'settings', 'is_active'])]
class Organization extends Model
{
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'settings' => 'array',
        ];
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return ($this->settings ?? [])[$key] ?? $default;
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
