<?php

namespace App\Http\Requests\EmissionFactor;

use Illuminate\Foundation\Http\FormRequest;

class StoreEmissionFactorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin();
    }

    public function rules(): array
    {
        return [
            'category_id' => ['required', 'exists:emission_categories,id'],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:100', 'unique:emission_factors,slug'],
            'description' => ['nullable', 'string'],
            'source_unit' => ['required', 'string', 'max:50'],
            'factor_value' => ['required', 'numeric', 'min:0'],
            'factor_unit' => ['nullable', 'string', 'max:50'],
            'source' => ['nullable', 'string', 'max:500'],
            'version' => ['nullable', 'string', 'max:20'],
        ];
    }

    public function messages(): array
    {
        return [
            'category_id.required' => 'Kategori wajib dipilih.',
            'category_id.exists' => 'Kategori tidak ditemukan.',
            'name.required' => 'Nama faktor emisi wajib diisi.',
            'slug.required' => 'Slug wajib diisi.',
            'slug.unique' => 'Slug sudah digunakan.',
            'source_unit.required' => 'Satuan input wajib diisi.',
            'factor_value.required' => 'Nilai faktor emisi wajib diisi.',
            'factor_value.min' => 'Nilai faktor emisi tidak boleh negatif.',
        ];
    }
}
