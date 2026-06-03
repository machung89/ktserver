<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'tax_code', 'address', 'city', 'ward', 'phone', 'email', 'website', 'logo_url', 'print_template', 'settings', 'is_active', 'subscription_ends_at', 'bank_id', 'bank_account_name', 'bank_account_number'])]
class Organization extends Model
{
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'settings' => 'array',
            'subscription_ends_at' => 'date',
        ];
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return ($this->settings ?? [])[$key] ?? $default;
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
