<?php

namespace App\Http\Requests\CarbonTarget;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCarbonTargetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'category_id'           => ['nullable', 'exists:emission_categories,id'],
            'period_type'           => ['required', 'in:monthly,quarterly,yearly'],
            'period_year'           => ['required', 'integer', 'min:2020', 'max:2100'],
            'period_value'          => ['nullable', 'integer', 'min:1', 'max:12'],
            'target_co2e_kg'        => ['required', 'numeric', 'min:0.01'],
            'baseline_co2e_kg'      => ['nullable', 'numeric', 'min:0'],
            'reduction_percentage'  => ['nullable', 'numeric', 'min:0', 'max:100'],
            'notes'                 => ['nullable', 'string', 'max:1000'],
        ];
    }
}
