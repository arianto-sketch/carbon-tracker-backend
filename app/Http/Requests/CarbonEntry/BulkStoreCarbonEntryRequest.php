<?php

namespace App\Http\Requests\CarbonEntry;

use Illuminate\Foundation\Http\FormRequest;

class BulkStoreCarbonEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'entries'                        => ['required', 'array', 'min:1', 'max:100'],
            'entries.*.emission_factor_id'   => ['required', 'exists:emission_factors,id'],
            'entries.*.entry_date'           => ['required', 'date', 'before_or_equal:tomorrow'],
            'entries.*.quantity'             => ['required', 'numeric', 'min:0.0001'],
            'entries.*.description'          => ['nullable', 'string', 'max:1000'],
            'entries.*.vendor_name'          => ['nullable', 'string', 'max:255'],
            'entries.*.activity_type'        => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'entries.required'                      => 'Data entries wajib diisi.',
            'entries.max'                           => 'Maksimal 100 entries per bulk import.',
            'entries.*.emission_factor_id.required' => 'Faktor emisi wajib dipilih di setiap baris.',
            'entries.*.quantity.min'                => 'Jumlah konsumsi harus lebih dari 0.',
        ];
    }
}
