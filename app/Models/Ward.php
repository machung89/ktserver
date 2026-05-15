<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Ward extends Model
{
    public $timestamps = false;

    protected $table = 'wards';

    protected $guarded = [];

    /**
     * Returns "Phường Ba Đình", "Xã An Khánh", etc.
     */
    public function getFullNameAttribute(): string
    {
        return ucfirst($this->_prefix ?? '').' '.$this->_name;
    }

    public function province()
    {
        return $this->belongsTo(Province::class, '_province_id');
    }
}
