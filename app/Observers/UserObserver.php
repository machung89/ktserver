<?php

namespace App\Observers;

use App\Enums\CompanyType;
use App\Models\Company;
use App\Models\User;

class UserObserver
{
    public function created(User $user): void
    {
        Company::withoutGlobalScopes()->create([
            'organization_id' => $user->organization_id,
            'user_id' => $user->id,
            'name' => $user->name,
            'type' => CompanyType::Employee,
            'email' => $user->email,
            'phone' => $user->phone,
            'is_active' => true,
        ]);
    }

    public function updated(User $user): void
    {
        Company::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->update([
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'is_active' => $user->is_active,
                'updated_at' => now(),
            ]);
    }

    public function deleted(User $user): void
    {
        Company::withoutGlobalScopes()
            ->where('user_id', $user->id)
            ->update(['user_id' => null, 'is_active' => false]);
    }
}
