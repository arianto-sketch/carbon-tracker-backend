<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CarbonTarget extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'project_id',
        'category_id',
        'period_type',
        'period_year',
        'period_value',
        'target_co2e_kg',
        'baseline_co2e_kg',
        'reduction_percentage',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'target_co2e_kg' => 'decimal:4',
            'baseline_co2e_kg' => 'decimal:4',
            'reduction_percentage' => 'decimal:2',
        ];
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function category()
    {
        return $this->belongsTo(EmissionCategory::class, 'category_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
