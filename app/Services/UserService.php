<?php

namespace App\Services;

use App\Models\AuditLog;
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

        DB::transaction(function () use ($user, $data, $deactivating, $demoting) {
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
}
