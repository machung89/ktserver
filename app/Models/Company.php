<?php

namespace App\Models;

use App\Enums\CompanyType;
use App\Models\Scopes\OwnedByOrganization;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'code', 'type', 'tax_code', 'phone', 'email', 'address', 'city', 'ward', 'representative', 'is_active', 'organization_id', 'user_id', 'bank_id', 'bank_account_name', 'bank_account_number'])]
class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::addGlobalScope(new OwnedByOrganization);
    }

    /**
     * Sinh mã đối tác tự động theo loại: Khách hàng=KH, Nhà cung cấp=NCC, Nhân viên=NV
     * (mặc định KH cho "cả hai"/không rõ). Mỗi tiền tố một dãy số riêng: KH0001, NCC0001…
     */
    public static function generateCode(int $organizationId, string|CompanyType|null $type): string
    {
        $value = $type instanceof CompanyType ? $type->value : $type;
        $prefix = match ($value) {
            CompanyType::Supplier->value => 'NCC',
            CompanyType::Employee->value => 'NV',
            default => 'KH',
        };

        $max = static::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('code', 'like', $prefix.'%')
            ->pluck('code')
            ->map(fn ($c) => (int) substr((string) $c, strlen($prefix)))
            ->max() ?? 0;

        return $prefix.str_pad($max + 1, 4, '0', STR_PAD_LEFT);
    }

    protected function casts(): array
    {
        return [
            'type' => CompanyType::class,
            'is_active' => 'boolean',
        ];
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function salesOrders(): HasMany
    {
        return $this->hasMany(SalesOrder::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
