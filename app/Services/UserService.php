<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class UserService
{
    public function __construct(private AuthService $authService) {}

    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return User::query()
            ->when($filters['search'] ?? null, function ($query, $search) {
                $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"));
            })
            ->when($filters['role'] ?? null, fn ($query, $role) => $query->where('role', $role))
            ->orderBy('name')
            ->paginate($perPage);
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

        if ($user->isAdmin() && $user->is_active && ($deactivating || $demoting) && $this->activeAdminCount() <= 1) {
            throw ValidationException::withMessages([
                ($demoting ? 'role' : 'is_active') => ['Minimal harus ada satu admin aktif.'],
            ]);
        }

        $user->update(Arr::only($data, ['name', 'role', 'is_active']));

        // User nonaktif langsung kehilangan semua sesi
        if ($deactivating) {
            $user->tokens()->delete();
        }

        return $user->fresh();
    }

    private function activeAdminCount(): int
    {
        return User::where('role', 'admin')->where('is_active', true)->count();
    }
}
