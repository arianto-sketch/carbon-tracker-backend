<?php

namespace App\Http\Requests\CarbonEntry;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCarbonEntryRequest extends FormRequest
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
            'quantity.required'           => 'Jumlah konsumsi wajib diisi.',
            'quantity.min'                => 'Jumlah konsumsi harus lebih dari 0.',
            'entry_date.before_or_equal'  => 'Tanggal aktivitas tidak boleh di masa depan.',
        ];
    }
}
