<?php

namespace App\Models;

use App\Notifications\ResetPasswordNotification;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /** Sama dengan default kolom, supaya model yang baru dibuat (belum dimuat ulang) sudah benar */
    protected $attributes = [
        'email_notifications' => true,
        'monthly_summary_email' => true,
    ];

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'avatar',
        'is_active',
        'email_notifications',
        'monthly_summary_email',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'email_notifications' => 'boolean',
            'monthly_summary_email' => 'boolean',
        ];
    }

    /** @return HasMany<ProjectMember, $this> */
    public function projectMembers(): HasMany
    {
        return $this->hasMany(ProjectMember::class);
    }

    /** @return BelongsToMany<Project, $this> */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'project_members', 'user_id', 'project_id')
            ->withPivot('role', 'joined_at')
            ->withTimestamps();
    }

    /** @return HasMany<CarbonEntry, $this> */
    public function carbonEntries(): HasMany
    {
        return $this->hasMany(CarbonEntry::class, 'created_by');
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isPm(): bool
    {
        return $this->role === 'pm';
    }

    /** Role global Viewer: hanya bisa melihat, apa pun role-nya di project */
    public function isViewer(): bool
    {
        return $this->role === 'viewer';
    }

    /** Link reset dikirim lewat antrean, mengarah ke halaman reset di SPA */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }
}
