<?php

namespace App\Http\Requests\EmissionFactor;

use App\Models\EmissionFactorValue;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFactorValueRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->isAdmin();
    }

    public function rules(): array
    {
        return [
            // Satu periode per tanggal mulai untuk tiap faktor
            'valid_from'   => ['required', 'date_format:Y-m-d', 'after_or_equal:'.EmissionFactorValue::FIRST_VALID_FROM,
                Rule::unique('emission_factor_values', 'valid_from')->where('emission_factor_id', $this->route('id'))],
            'factor_value' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'source'       => ['nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'valid_from.unique' => 'Sudah ada nilai yang berlaku mulai tanggal ini. Ubah nilai periode itu saja.',
        ];
    }
}
