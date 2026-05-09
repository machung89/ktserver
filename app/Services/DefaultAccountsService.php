<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class DefaultAccountsService
{
    /**
     * Copy the standard VAS chart of accounts into the given organization.
     * Uses INSERT IGNORE so it is safe to call multiple times.
     */
    public function seedForOrganization(int $organizationId): void
    {
        $templates = DB::table('system_accounts')->orderBy('sort_order')->get();

        if ($templates->isEmpty()) {
            return;
        }

        $now = now();

        $rows = $templates->map(fn ($t) => [
            'code' => $t->code,
            'name' => $t->name,
            'type' => $t->type,
            'parent_code' => $t->parent_code,
            'organization_id' => $organizationId,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        foreach (array_chunk($rows, 50) as $chunk) {
            DB::table('accounts')->insertOrIgnore($chunk);
        }
    }
}
