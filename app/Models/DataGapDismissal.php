<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Penanda "tidak ada data bulan ini" untuk faktor rutin (atau seluruh project bila faktor null) */
class DataGapDismissal extends Model
{
    protected $fillable = [
        'project_id',
        'emission_factor_id',
        'period_year',
        'period_month',
        'dismissed_by',
    ];

    protected function casts(): array
    {
        return [
            'period_year' => 'integer',
            'period_month' => 'integer',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
