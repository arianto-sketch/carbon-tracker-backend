<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\TransientToken;

class AuthService
{
    public function login(string $email, string $password, string $deviceName = 'api'): array
    {
        $user = User::where('email', $email)->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Email atau password salah.'],
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'email' => ['Akun Anda tidak aktif. Hubungi administrator.'],
            ]);
        }

        $token = $user->createToken($deviceName)->plainTextToken;

        return [
            'user' => $user,
            'token' => $token,
        ];
    }

    public function logout(User $user): void
    {
        $user->currentAccessToken()->delete();
    }

    public function register(array $data): User
    {
        return User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'role' => $data['role'] ?? 'pm',
            'is_active' => true,
        ]);
    }

    public function updateProfile(User $user, array $data): User
    {
        $attributes = array_filter([
            'name' => $data['name'] ?? null,
            'avatar' => $data['avatar'] ?? null,
        ]);

        // Di luar array_filter: nilai false (mematikan email) harus tetap tersimpan
        if (array_key_exists('email_notifications', $data)) {
            $attributes['email_notifications'] = (bool) $data['email_notifications'];
        }

        $user->update($attributes);

        return $user->fresh();
    }

    public function changePassword(User $user, string $currentPassword, string $newPassword): void
    {
        if (! Hash::check($currentPassword, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Password saat ini tidak sesuai.'],
            ]);
        }

        $user->update(['password' => Hash::make($newPassword)]);

        // Cabut sesi di perangkat lain; token yang sedang dipakai tetap berlaku.
        // Docblock Sanctum menyatakan selalu PersonalAccessToken, padahal bisa TransientToken (auth cookie/SPA)
        // atau null (mis. login lewat actingAs di test); hanya PersonalAccessToken yang punya id.
        /** @var PersonalAccessToken|TransientToken|null $current */
        $current = $user->currentAccessToken();
        $user->tokens()
            ->when($current instanceof PersonalAccessToken, fn ($q) => $q->whereKeyNot($current->getKey()))
            ->delete();
    }
}
