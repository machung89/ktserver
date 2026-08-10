<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

#[Fillable(['public_token', 'name', 'tax_code', 'address', 'city', 'ward', 'phone', 'email', 'website', 'logo_url', 'print_template', 'settings', 'is_active', 'subscription_ends_at', 'bank_id', 'bank_account_name', 'bank_account_number'])]
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

    public function pushSubscriptions(): HasMany
    {
        return $this->hasMany(PushSubscription::class);
    }

    /** Token cửa hàng công khai — sinh nếu chưa có. */
    public function ensurePublicToken(): string
    {
        if (empty($this->public_token)) {
            $this->update(['public_token' => Str::random(40)]);
        }

        return $this->public_token;
    }
}
