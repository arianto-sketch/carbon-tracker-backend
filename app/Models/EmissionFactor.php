<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EmissionFactor extends Model
{
    protected $fillable = [
        'category_id',
        'name',
        'slug',
        'description',
        'source_unit',
        'factor_value',
        'factor_unit',
        'source',
        'version',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'factor_value' => 'decimal:8',
            'is_active' => 'boolean',
        ];
    }

    public function category()
    {
        return $this->belongsTo(EmissionCategory::class, 'category_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function carbonEntries()
    {
        return $this->hasMany(CarbonEntry::class, 'emission_factor_id');
    }
}
