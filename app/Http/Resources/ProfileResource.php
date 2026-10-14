<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/**
 * Data akun untuk pemiliknya sendiri (login, /auth/me): UserResource ditambah preferensi pribadi
 * yang tidak perlu terlihat oleh admin atau anggota project lain.
 */
class ProfileResource extends UserResource
{
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'email_notifications' => $this->email_notifications,
        ];
    }
}
