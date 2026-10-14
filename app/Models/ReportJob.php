<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportJob extends Model
{
    protected $fillable = [
        'user_id',
        'filters',
        'format',
        'status',
        'file_path',
        'file_name',
        'error_message',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'filters'      => 'array',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isDone(): bool
    {
        return $this->status === 'done';
    }
}
