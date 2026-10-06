<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isAdmin();
    }

    public function rules(): array
    {
        return [
            'name'      => ['sometimes', 'required', 'string', 'max:255'],
            'role'      => ['sometimes', 'required', 'in:admin,pm,viewer'],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'      => 'Nama wajib diisi.',
            'role.in'            => 'Role tidak valid. Pilih: admin, pm, atau viewer.',
            'is_active.boolean'  => 'Status aktif tidak valid.',
        ];
    }
}
