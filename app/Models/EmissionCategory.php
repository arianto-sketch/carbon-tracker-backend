<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmissionCategory extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'icon',
        'sort_order',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function emissionFactors()
    {
        return $this->hasMany(EmissionFactor::class, 'category_id');
    }

    public function carbonEntries()
    {
        return $this->hasMany(CarbonEntry::class, 'category_id');
    }
}
