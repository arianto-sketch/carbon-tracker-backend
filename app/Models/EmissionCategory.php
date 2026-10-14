<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    /** @return HasMany<EmissionFactor, $this> */
    public function emissionFactors(): HasMany
    {
        return $this->hasMany(EmissionFactor::class, 'category_id');
    }

    /** @return HasMany<CarbonEntry, $this> */
    public function carbonEntries(): HasMany
    {
        return $this->hasMany(CarbonEntry::class, 'category_id');
    }
}
