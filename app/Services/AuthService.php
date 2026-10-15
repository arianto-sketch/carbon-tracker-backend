<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
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

    /**
     * Lupa password: kirim link reset hanya bila email milik akun aktif. Tidak ada beda respons
     * untuk email yang tidak terdaftar, supaya daftar email user tidak bisa ditebak.
     */
    public function requestPasswordReset(string $email): void
    {
        $user = User::whereRaw('LOWER(email) = ?', [Str::lower(trim($email))])->where('is_active', true)->first();

        if ($user) {
            // Broker membatasi 1 link per user per menit; permintaan yang terlalu cepat diabaikan diam-diam
            Password::broker()->sendResetLink(['email' => $user->email]);
        }
    }

    /** Admin mengirim link reset ke user (mis. user lupa password dan tidak bisa membuka halaman lupa password) */
    public function sendPasswordResetLink(User $user, User $admin): void
    {
        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'user' => ['User nonaktif tidak bisa menerima link reset password. Aktifkan dulu akunnya.'],
            ]);
        }

        if (Password::broker()->sendResetLink(['email' => $user->email]) === Password::RESET_THROTTLED) {
            throw ValidationException::withMessages([
                'user' => ['Link reset baru saja dikirim. Tunggu satu menit sebelum mengirim lagi.'],
            ]);
        }

        AuditLog::record('password_reset_link_sent', $user, actorId: $admin->id);
    }

    /** @param  array{token: string, email: string, password: string}  $data */
    public function resetPassword(array $data): void
    {
        $status = Password::broker()->reset(
            ['email' => $data['email'], 'token' => $data['token'], 'password' => $data['password']],
            function (User $user, string $password) {
                $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
                // Semua sesi lama dicabut: siapa pun yang masih memegang token lama harus login ulang
                $user->tokens()->delete();
                AuditLog::record('password_reset', $user, actorId: $user->id);
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => ['Link reset password tidak valid atau sudah kedaluwarsa.'],
            ]);
        }
    }
}
