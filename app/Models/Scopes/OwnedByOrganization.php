<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class OwnedByOrganization implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (app()->bound('orgId')) {
            $builder->where($model->getTable().'.organization_id', app('orgId'));
        }
    }
}
