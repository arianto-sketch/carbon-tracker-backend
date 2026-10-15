<?php

namespace App\Http\Requests\EmissionFactor;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFactorValueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isAdmin();
    }

    public function rules(): array
    {
        // Tanggal mulai tidak diubah: hapus periode lalu tambahkan yang baru bila tanggalnya salah
        return [
            'factor_value' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'source'       => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
