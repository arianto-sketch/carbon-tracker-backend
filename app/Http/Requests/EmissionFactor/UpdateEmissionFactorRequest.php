<?php

namespace App\Http\Requests\EmissionFactor;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmissionFactorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin();
    }

    public function rules(): array
    {
        $factorId = $this->route('id');

        return [
            'category_id' => ['required', 'exists:emission_categories,id'],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:100', Rule::unique('emission_factors', 'slug')->ignore($factorId)],
            'description' => ['nullable', 'string'],
            'source_unit' => ['required', 'string', 'max:50'],
            'factor_value' => ['required', 'numeric', 'min:0'],
            'factor_unit' => ['nullable', 'string', 'max:50'],
            'source' => ['nullable', 'string', 'max:500'],
            'version' => ['nullable', 'string', 'max:20'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
