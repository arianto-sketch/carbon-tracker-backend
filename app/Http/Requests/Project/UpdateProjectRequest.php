<?php

namespace App\Http\Requests\Project;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $projectId = $this->route('id');

        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', Rule::unique('projects', 'code')->ignore($projectId)],
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
