<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CarbonEntry extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'project_id',
        'emission_factor_id',
        'category_id',
        'entry_date',
        'period_month',
        'period_year',
        'quantity',
        'source_unit',
        'emission_factor_value',
        'co2e_kg',
        'description',
        'vendor_name',
        'activity_type',
        'attachment_path',
        'status',
        'approved_by',
        'approved_at',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'approved_at' => 'datetime',
            'quantity' => 'decimal:4',
            'emission_factor_value' => 'decimal:8',
            'co2e_kg' => 'decimal:4',
        ];
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function emissionFactor()
    {
        return $this->belongsTo(EmissionFactor::class, 'emission_factor_id');
    }

    public function category()
    {
        return $this->belongsTo(EmissionCategory::class, 'category_id');
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }
}
