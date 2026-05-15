<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Province extends Model
{
    public $timestamps = false;

    protected $table = 'provinces';

    protected $guarded = [];

    /**
     * Strip prefix words like "tỉnh", "thành phố", "thủ đô" from the raw _name.
     */
    public function getNameAttribute(): string
    {
        $raw = $this->attributes['_name'] ?? '';
        $raw = preg_replace('/^(thủ đô|thành phố|tỉnh)\s+/iu', '', $raw);

        return ucfirst($raw);
    }

    public function wards()
    {
        return $this->hasMany(Ward::class, '_province_id');
    }
}
