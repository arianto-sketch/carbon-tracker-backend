<?php

namespace App\Services;

use App\Models\EmissionCategory;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Support\Like;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProjectService
{
    public function list(User $user, int $perPage = 15, ?string $search = null): LengthAwarePaginator
    {
        // Role user di tiap project ikut dalam query yang sama (subquery), bukan 1 query per project
        $query = Project::with('createdBy')
            ->addSelect(['projects.*', 'current_user_role' => ProjectMember::select('role')
                ->whereColumn('project_members.project_id', 'projects.id')
                ->where('project_members.user_id', $user->id)
                ->limit(1)])
            ->when($search, fn ($q) => Like::anyContains($q, ['name', 'code'], $search));

        if (! $user->isAdmin()) {
            $query->whereHas('projectMembers', fn ($q) => $q->where('user_id', $user->id));
        }

        return $query->orderByDesc('created_at')->paginate($perPage);
    }

    public function create(array $data, User $creator): Project
    {
        $project = Project::create([
            'name' => $data['name'],
            'code' => strtoupper($data['code']),
            'description' => $data['description'] ?? null,
            'client_name' => $data['client_name'] ?? null,
            'team_size' => $data['team_size'] ?? null,
            'status' => $data['status'] ?? 'active',
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'] ?? null,
            'created_by' => $creator->id,
        ]);

        // Creator otomatis jadi owner
        // joined_at diisi eksplisit: default DB (useCurrent) tidak ikut termuat di model hasil create()
        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $creator->id,
            'role' => 'owner',
            'joined_at' => now(),
        ]);

        return $project->load('createdBy', 'projectMembers.user');
    }

    public function update(Project $project, array $data, User $updater): Project
    {
        $project->update([
            'name' => $data['name'],
            'code' => strtoupper($data['code']),
            'description' => $data['description'] ?? null,
            'client_name' => $data['client_name'] ?? null,
            // Klien lama yang tidak mengirim field ini tidak menghapus nilainya; null eksplisit = dikosongkan
            'team_size' => array_key_exists('team_size', $data) ? $data['team_size'] : $project->team_size,
            'status' => $data['status'] ?? $project->status,
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'] ?? null,
            'updated_by' => $updater->id,
        ]);

        return $project->fresh(['createdBy', 'projectMembers.user']);
    }

    public function delete(Project $project): void
    {
        $project->delete();
    }

    /** @param  User  $actor  pengelola yang meminta (owner project atau admin), dicek ulang di dalam transaksi */
    public function addMember(Project $project, int $userId, string $role, User $actor): ProjectMember
    {
        return DB::transaction(function () use ($project, $userId, $role, $actor) {
            $this->ensureCanManageMembers($actor, $this->lockedOwners($project));

            if ($project->hasUser($userId)) {
                throw ValidationException::withMessages([
                    'user_id' => ['User sudah menjadi member project ini.'],
                ]);
            }

            $this->ensureRoleAllowedFor(User::findOrFail($userId), $role);

            return ProjectMember::create([
                'project_id' => $project->id,
                'user_id' => $userId,
                'role' => $role,
                'joined_at' => now(),
            ]);
        });
    }

    public function updateMemberRole(Project $project, int $userId, string $role, User $actor): ProjectMember
    {
        return DB::transaction(function () use ($project, $userId, $role, $actor) {
            // Kunci semua owner dulu: dua owner yang saling menurunkan secara bersamaan diproses
            // bergiliran, jadi yang kedua melihat jumlah owner terbaru.
            $lockedOwners = $this->lockedOwners($project);
            $this->ensureCanManageMembers($actor, $lockedOwners);
            $member = $this->lockedMember($project, $userId);
            $this->ensureRoleAllowedFor($member->user, $role);

            // Hanya owner yang benar-benar bisa mengelola (bukan viewer) yang dijaga minimal satu
            if ($lockedOwners->contains('user_id', $userId) && $role !== 'owner' && $lockedOwners->count() <= 1) {
                throw ValidationException::withMessages([
                    'role' => ['Project harus punya minimal satu owner. Jadikan anggota lain owner terlebih dahulu.'],
                ]);
            }

            $member->update(['role' => $role]);

            return $member->fresh('user');
        });
    }

    public function removeMember(Project $project, int $userId, User $actor): void
    {
        DB::transaction(function () use ($project, $userId, $actor) {
            $this->ensureCanManageMembers($actor, $this->lockedOwners($project));

            // Role dicek pada baris terkunci: anggota yang baru saja dijadikan owner tidak ikut terhapus
            $member = $this->lockedMember($project, $userId);

            if ($member->role === 'owner') {
                throw ValidationException::withMessages([
                    'user_id' => ['Owner project tidak bisa dihapus dari member.'],
                ]);
            }

            $member->delete();
        });
    }

    /**
     * Semua owner project, dikunci (wajib di dalam transaksi). Baris project dikunci lebih dulu sebagai
     * mutex: operasi member pada project yang sama mengantre di sini, sebelum menyentuh baris member,
     * sehingga gap lock InnoDB (mis. tambah member ke project tanpa owner) tidak saling deadlock.
     */
    private function lockedOwners(Project $project): Collection
    {
        Project::whereKey($project->id)->lockForUpdate()->firstOrFail();

        // Owner ber-role global Viewer (data lama) tidak dihitung: ia tidak bisa mengelola project
        return ProjectMember::where('project_id', $project->id)->where('role', 'owner')
            ->whereHas('user', fn ($q) => $q->where('role', '!=', 'viewer'))
            ->orderBy('id')->lockForUpdate()->get(['id', 'user_id']);
    }

    /**
     * Cek pengelola member diulang pada baris owner yang terkunci: owner yang diturunkan request lain
     * setelah lolos cek di controller tidak bisa lagi mengubah member.
     */
    private function ensureCanManageMembers(User $actor, Collection $lockedOwners): void
    {
        // Viewer global tidak bisa mengelola walau membership lamanya masih owner
        if ($actor->isAdmin() || (! $actor->isViewer() && $lockedOwners->contains('user_id', $actor->id))) {
            return;
        }

        throw new AuthorizationException('Hanya owner atau admin yang bisa mengelola member project.');
    }

    /** User ber-role global Viewer hanya boleh menjadi viewer di project */
    private function ensureRoleAllowedFor(User $user, string $role): void
    {
        if ($user->isViewer() && $role !== 'viewer') {
            throw ValidationException::withMessages([
                'role' => ['User dengan role Viewer hanya bisa menjadi viewer di project. Ubah role global-nya lebih dulu.'],
            ]);
        }
    }

    private function lockedMember(Project $project, int $userId): ProjectMember
    {
        return ProjectMember::where('project_id', $project->id)
            ->where('user_id', $userId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    public function getSummary(Project $project): array
    {
        $entries = $project->carbonEntries()->where('status', 'approved');

        // Baris agregat (bukan model): nama kategori dimuat sekali lewat query terpisah
        $byCategory = (clone $entries)
            ->selectRaw('category_id, SUM(co2e_kg) as total_co2e_kg, COUNT(id) as count')
            ->groupBy('category_id')
            ->toBase()
            ->get();
        $categories = EmissionCategory::whereIn('id', $byCategory->pluck('category_id'))->get()->keyBy('id');

        $total = (float) $entries->sum('co2e_kg');

        return [
            'total_co2e_kg' => $total,
            'entry_count' => $entries->count(),
            'team_size' => $project->team_size,
            'intensity_kg_per_person' => $project->intensityFor($total),
            'by_category' => $byCategory->map(fn ($row) => [
                'category_id' => $row->category_id,
                'category_name' => $categories[$row->category_id]->name ?? null,
                'total_co2e_kg' => (float) $row->total_co2e_kg,
                'count' => $row->count,
            ]),
        ];
    }
}
