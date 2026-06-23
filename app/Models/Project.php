<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Project extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'code',
        'description',
        'client_name',
        'status',
        'start_date',
        'end_date',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
        ];
    }

    public function members()
    {
        return $this->belongsToMany(User::class, 'project_members', 'project_id', 'user_id')
            ->withPivot('role', 'joined_at')
            ->withTimestamps();
    }

    public function projectMembers()
    {
        return $this->hasMany(ProjectMember::class);
    }

    public function carbonEntries()
    {
        return $this->hasMany(CarbonEntry::class);
    }

    public function carbonTargets()
    {
        return $this->hasMany(CarbonTarget::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function hasUser(int $userId): bool
    {
        return $this->projectMembers()->where('user_id', $userId)->exists();
    }

    public function getUserRole(int $userId): ?string
    {
        return $this->projectMembers()->where('user_id', $userId)->value('role');
    }
}
