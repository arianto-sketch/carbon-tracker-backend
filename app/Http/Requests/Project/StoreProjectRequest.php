<?php

namespace App\Http\Requests\Project;

use Illuminate\Foundation\Http\FormRequest;

class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() || $this->user()?->isPm();
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', 'unique:projects,code'],
            'description' => ['nullable', 'string'],
            'client_name' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:active,completed,archived'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Nama project wajib diisi.',
            'code.required' => 'Kode project wajib diisi.',
            'code.unique' => 'Kode project sudah digunakan.',
            'start_date.required' => 'Tanggal mulai wajib diisi.',
            'end_date.after_or_equal' => 'Tanggal selesai harus setelah tanggal mulai.',
        ];
    }
}
