<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Project extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'code',
        'description',
        'client_name',
        'team_size',
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
            'team_size' => 'integer',
        ];
    }

    /** @return BelongsToMany<User, $this> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_members', 'project_id', 'user_id')
            ->withPivot('role', 'joined_at')
            ->withTimestamps();
    }

    /** @return HasMany<ProjectMember, $this> */
    public function projectMembers(): HasMany
    {
        return $this->hasMany(ProjectMember::class);
    }

    /** @return HasMany<CarbonEntry, $this> */
    public function carbonEntries(): HasMany
    {
        return $this->hasMany(CarbonEntry::class);
    }

    /** @return HasMany<ReductionInitiative, $this> */
    public function reductionInitiatives(): HasMany
    {
        return $this->hasMany(ReductionInitiative::class);
    }

    /** Emisi per anggota tim (kg CO2e/orang); null bila jumlah anggota tim belum diisi */
    public function intensityFor(float $totalCo2eKg): ?float
    {
        return $this->team_size > 0 ? round($totalCo2eKg / $this->team_size, 4) : null;
    }

    /** @return HasMany<CarbonTarget, $this> */
    public function carbonTargets(): HasMany
    {
        return $this->hasMany(CarbonTarget::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
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

    /**
     * Role yang menentukan wewenang user di project: role global Viewer selalu hanya-baca,
     * walau membership-nya (data lama) masih owner/member. Null = bukan anggota.
     */
    public function effectiveRole(User $user): ?string
    {
        return self::effectiveRoleOf($user, $this->getUserRole($user->id));
    }

    /** Sama dengan effectiveRole() untuk role membership yang sudah dimuat (mis. dari subquery) */
    public static function effectiveRoleOf(User $user, ?string $membershipRole): ?string
    {
        return $membershipRole !== null && $user->isViewer() ? 'viewer' : $membershipRole;
    }
}
