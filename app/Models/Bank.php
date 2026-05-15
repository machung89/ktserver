<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['id', 'name', 'code', 'bin', 'short_name', 'logo', 'swift_code', 'transfer_supported', 'lookup_supported'])]
class Bank extends Model
{
    public $incrementing = false;

    protected $casts = [
        'transfer_supported' => 'boolean',
        'lookup_supported' => 'boolean',
    ];
}
