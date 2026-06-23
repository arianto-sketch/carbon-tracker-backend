<?php

namespace App\Http\Requests\CarbonEntry;

use Illuminate\Foundation\Http\FormRequest;

class StoreCarbonEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'emission_factor_id' => ['required', 'exists:emission_factors,id'],
            'entry_date'         => ['required', 'date', 'before_or_equal:tomorrow'],
            'quantity'           => ['required', 'numeric', 'min:0.0001'],
            'description'        => ['nullable', 'string', 'max:1000'],
            'vendor_name'        => ['nullable', 'string', 'max:255'],
            'activity_type'      => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'emission_factor_id.required' => 'Faktor emisi wajib dipilih.',
            'emission_factor_id.exists'   => 'Faktor emisi tidak ditemukan.',
            'entry_date.required'         => 'Tanggal aktivitas wajib diisi.',
            'entry_date.before_or_equal'  => 'Tanggal aktivitas tidak boleh di masa depan.',
            'quantity.required'           => 'Jumlah konsumsi wajib diisi.',
            'quantity.min'                => 'Jumlah konsumsi harus lebih dari 0.',
        ];
    }
}
