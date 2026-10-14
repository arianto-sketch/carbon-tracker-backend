<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmissionFactor extends Model
{
    /** Scope GHG Protocol: 1 = emisi langsung, 2 = energi yang dibeli, 3 = tidak langsung lainnya */
    public const SCOPES = [1, 2, 3];

    protected $fillable = [
        'category_id',
        'scope',
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
            'scope' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<EmissionCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(EmissionCategory::class, 'category_id');
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<CarbonEntry, $this> */
    public function carbonEntries(): HasMany
    {
        return $this->hasMany(CarbonEntry::class, 'emission_factor_id');
    }
}
