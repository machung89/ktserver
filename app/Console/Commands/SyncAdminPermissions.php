<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\DefaultRolesService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:sync-admin-permissions')]
#[Description('Sync admin role permissions for all organizations')]
class SyncAdminPermissions extends Command
{
    public function handle(DefaultRolesService $service): void
    {
        $orgs = Organization::all();
        foreach ($orgs as $org) {
            $service->seedForOrganization($org->id);
            $this->line("Synced org #{$org->id}: {$org->name}");
        }
        $this->info('Done.');
    }
}
