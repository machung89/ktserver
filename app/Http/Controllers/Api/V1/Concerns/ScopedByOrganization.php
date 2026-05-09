<?php

namespace App\Http\Controllers\Api\V1\Concerns;

trait ScopedByOrganization
{
    protected function orgId(): int
    {
        return app('orgId');
    }
}
