<?php

namespace App\Observers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Jejak perubahan akun: siapa mengubah nama, role, atau status aktif user, dan kapan.
 * Password, token, dan kolom teknis tidak pernah dicatat.
 */
class UserObserver
{
    private const AUDITED = ['name', 'role', 'is_active'];

    public function created(User $user): void
    {
        $this->log('created', $user, null, $user->only(self::AUDITED));
    }

    public function updated(User $user): void
    {
        $changed = array_keys(Arr::only($user->getChanges(), self::AUDITED));

        if ($changed === []) {
            return;
        }

        // Di event "updated", getOriginal() masih berisi nilai sebelum disimpan
        $this->log('updated', $user, Arr::only($user->getOriginal(), $changed), $user->only($changed));
    }

    private function log(string $action, User $user, ?array $oldValues, array $newValues): void
    {
        AuditLog::create([
            'user_id'    => Auth::id(),
            'action'     => $action,
            'model_type' => User::class,
            'model_id'   => $user->id,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
            'created_at' => now(),
        ]);
    }
}
