<?php

namespace App\Http\Requests\CarbonTarget;

use Illuminate\Foundation\Http\FormRequest;

class StoreCarbonTargetRequest extends FormRequest
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
            // yearly: period_value diabaikan (disimpan null); monthly 1-12; quarterly 1-4
            'period_value'          => [
                'exclude_if:period_type,yearly',
                'required_if:period_type,monthly,quarterly',
                'integer',
                'min:1',
                $this->input('period_type') === 'quarterly' ? 'max:4' : 'max:12',
            ],
            'target_co2e_kg'        => ['required', 'numeric', 'min:0.01'],
            'baseline_co2e_kg'      => ['nullable', 'numeric', 'min:0'],
            'reduction_percentage'  => ['nullable', 'numeric', 'min:0', 'max:100'],
            'notes'                 => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'period_type.required'      => 'Tipe periode wajib dipilih.',
            'period_type.in'            => 'Tipe periode tidak valid. Pilih: monthly, quarterly, atau yearly.',
            'period_year.required'      => 'Tahun target wajib diisi.',
            'period_value.required_if'  => 'Bulan/kuartal wajib diisi untuk target bulanan atau kuartalan.',
            'period_value.min'          => 'Nilai periode minimal 1.',
            'period_value.max'          => $this->input('period_type') === 'quarterly'
                ? 'Kuartal harus antara 1 sampai 4.'
                : 'Bulan harus antara 1 sampai 12.',
            'target_co2e_kg.required'   => 'Target emisi (kg CO₂e) wajib diisi.',
            'target_co2e_kg.min'        => 'Target emisi harus lebih dari 0.',
            'reduction_percentage.max'  => 'Persentase pengurangan maksimal 100%.',
        ];
    }
}
