<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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

    public function user()
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
