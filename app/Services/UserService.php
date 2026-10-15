<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\ProjectMember;
use App\Models\User;
use App\Support\Like;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserService
{
    /** Batas entri riwayat yang dikirim ke dialog Riwayat (terbaru dulu) */
    public const HISTORY_LIMIT = 100;

    public function __construct(private AuthService $authService) {}

    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return User::query()
            ->when($filters['search'] ?? null, fn ($query, $search) => Like::anyContains($query, ['name', 'email'], $search))
            ->when($filters['role'] ?? null, fn ($query, $role) => $query->where('role', $role))
            ->orderBy('name')
            ->paginate($perPage);
    }

    /**
     * Pencarian user aktif untuk ditambahkan ke project (owner PM/admin), hanya kolom yang perlu.
     *
     * @return array<int, array{id: int, name: string, email: string, role: string}>
     */
    public function lookup(string $search, int $limit = 10): array
    {
        $query = User::query()->where('is_active', true);
        Like::anyContains($query, ['name', 'email'], $search);

        return $query->orderBy('name')
            ->limit($limit)
            ->get(['id', 'name', 'email', 'role'])
            ->map(fn (User $user) => $user->only(['id', 'name', 'email', 'role']))
            ->all();
    }

    /**
     * Riwayat perubahan akun dari audit_logs (ditulis UserObserver), terbaru di atas, maksimal HISTORY_LIMIT entri.
     *
     * @return array<int, array<string, mixed>>
     */
    public function history(User $user): array
    {
        return AuditLog::with('user')
            ->where('model_type', User::class)
            ->where('model_id', $user->id)
            ->orderByDesc('id')
            ->limit(self::HISTORY_LIMIT)
            ->get()
            ->map(function (AuditLog $log) {
                $changes = [];
                foreach ($log->new_values ?? [] as $field => $new) {
                    $changes[$field] = ['old' => $log->old_values[$field] ?? null, 'new' => $new];
                }

                return [
                    'id'         => $log->id,
                    'action'     => $log->action,
                    'user'       => $log->user ? ['id' => $log->user->id, 'name' => $log->user->name] : null,
                    'changes'    => (object) $changes,
                    'created_at' => $log->created_at->toISOString(),
                ];
            })
            ->all();
    }

    public function create(array $data): User
    {
        return $this->authService->register($data);
    }

    public function update(User $user, array $data, User $actor): User
    {
        $deactivating = array_key_exists('is_active', $data) && ! $data['is_active'];
        $demoting = isset($data['role']) && $data['role'] !== 'admin';

        if ($deactivating && $user->is($actor)) {
            throw ValidationException::withMessages([
                'is_active' => ['Anda tidak bisa menonaktifkan akun sendiri.'],
            ]);
        }

        $toViewer = ($data['role'] ?? null) === 'viewer' && ! $user->isViewer();

        DB::transaction(function () use ($user, $data, $actor, $deactivating, $demoting, $toViewer) {
            if ($toViewer) {
                $this->demoteMembershipsToViewer($user, $actor);
            }

            if ($deactivating || $demoting) {
                // Kunci semua admin aktif: dua admin yang saling menurunkan/menonaktifkan secara
                // bersamaan diproses bergiliran, jadi yang kedua melihat jumlah admin terbaru.
                $activeAdminIds = User::where('role', 'admin')->where('is_active', true)->orderBy('id')->lockForUpdate()->pluck('id');

                if ($activeAdminIds->contains($user->id) && $activeAdminIds->count() <= 1) {
                    throw ValidationException::withMessages([
                        ($demoting ? 'role' : 'is_active') => ['Minimal harus ada satu admin aktif.'],
                    ]);
                }
            }

            $user->update(Arr::only($data, ['name', 'role', 'is_active']));

            // User nonaktif langsung kehilangan semua sesi
            if ($deactivating) {
                $user->tokens()->delete();
            }
        });

        return $user->fresh();
    }

    /**
     * Role global Viewer hanya boleh membaca: membership owner/member diturunkan menjadi viewer (tercatat
     * di audit log). Ditolak bila user satu-satunya owner sebuah project (aturan minimal satu owner).
     */
    private function demoteMembershipsToViewer(User $user, User $actor): void
    {
        $memberships = ProjectMember::with('project')
            ->where('user_id', $user->id)
            ->whereIn('role', ['owner', 'member'])
            ->lockForUpdate()
            ->get();

        // Owner lain yang dihitung hanya yang bukan viewer global (viewer tidak bisa mengelola project)
        $soleOwnerOf = $memberships
            ->filter(fn (ProjectMember $m) => $m->role === 'owner' && $m->project !== null)
            ->reject(fn (ProjectMember $m) => ProjectMember::where('project_id', $m->project_id)
                ->where('role', 'owner')
                ->whereKeyNot($m->getKey())
                ->whereHas('user', fn ($q) => $q->where('role', '!=', 'viewer'))
                ->exists());

        if ($soleOwnerOf->isNotEmpty()) {
            $projects = $soleOwnerOf->map(fn (ProjectMember $m) => $m->project?->name)->implode(', ');

            throw ValidationException::withMessages([
                'role' => ["Tidak bisa dijadikan Viewer: user ini satu-satunya owner di project {$projects}. Tunjuk owner lain terlebih dahulu."],
            ]);
        }

        foreach ($memberships as $membership) {
            $oldRole = $membership->role;
            $membership->update(['role' => 'viewer']);
            AuditLog::record('updated', $membership, ['role' => $oldRole], ['role' => 'viewer'], $actor->id);
        }
    }
}
