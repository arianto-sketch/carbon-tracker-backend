<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Inisiatif pengurangan emisi dalam rencana aksi sebuah project */
class ReductionInitiative extends Model
{
    use SoftDeletes;

    public const STATUSES = ['planned', 'in_progress', 'done', 'cancelled'];

    /** Status yang dihitung sebagai potensi reduksi yang masih direncanakan/berjalan */
    public const OPEN_STATUSES = ['planned', 'in_progress'];

    protected $fillable = [
        'project_id',
        'title',
        'description',
        'category_id',
        'estimated_reduction_kg',
        'status',
        'pic_user_id',
        'target_date',
        'completed_at',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'estimated_reduction_kg' => 'decimal:4',
            'target_date' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<EmissionCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(EmissionCategory::class, 'category_id');
    }

    /** @return BelongsTo<User, $this> */
    public function pic(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pic_user_id');
    }
}
