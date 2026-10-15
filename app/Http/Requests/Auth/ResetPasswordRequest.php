<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'token'    => ['required', 'string', 'max:255'],
            'email'    => ['required', 'string', 'email', 'max:255'],
            // Aturan sama dengan ganti password di Profil
            'password' => ['required', 'confirmed', Password::min(8)],
        ];
    }
}
