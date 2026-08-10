<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['organization_id', 'endpoint', 'endpoint_hash', 'public_key', 'auth_token', 'content_encoding'])]
class PushSubscription extends Model
{
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
