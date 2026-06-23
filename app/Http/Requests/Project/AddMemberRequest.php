<?php

namespace App\Http\Requests\Project;

use Illuminate\Foundation\Http\FormRequest;

class AddMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['required', 'exists:users,id'],
            'role' => ['nullable', 'in:owner,member,viewer'],
        ];
    }

    public function messages(): array
    {
        return [
            'user_id.required' => 'User wajib dipilih.',
            'user_id.exists' => 'User tidak ditemukan.',
            'role.in' => 'Role tidak valid. Pilih: owner, member, atau viewer.',
        ];
    }
}
