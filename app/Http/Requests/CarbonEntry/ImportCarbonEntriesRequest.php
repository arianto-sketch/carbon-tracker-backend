<?php

namespace App\Http\Requests\CarbonEntry;

use App\Http\Controllers\Concerns\AuthorizesProjectEntries;
use App\Models\Project;
use App\Services\CarbonEntryImportService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Baris hasil preview yang dikonfirmasi user; divalidasi ulang sebelum disimpan.
 */
class ImportCarbonEntriesRequest extends FormRequest
{
    use AuthorizesProjectEntries;

    private ?Project $project = null;

    /**
     * Akses dicek sebelum aturan validasi dijalankan: tanpa ini non-member bisa memicu
     * sampai MAX_ROWS query `exists` per request sebelum akhirnya ditolak.
     */
    public function authorize(): bool
    {
        $this->authorizeProjectWrite($this, $this->project());

        return true;
    }

    /** Project dari URL, dimuat sekali dan dipakai ulang controller. */
    public function project(): Project
    {
        return $this->project ??= Project::findOrFail($this->route('projectId'));
    }

    public function rules(): array
    {
        return [
            'rows'                      => ['required', 'array', 'min:1', 'max:'.CarbonEntryImportService::MAX_ROWS],
            'rows.*.entry_date'         => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now(config('app.business_timezone'))->toDateString()],
            'rows.*.emission_factor_id' => ['required', Rule::exists('emission_factors', 'id')->where('is_active', true)],
            'rows.*.quantity'           => ['required', 'numeric', 'min:0.0001', 'max:'.CarbonEntryImportService::MAX_QUANTITY],
            'rows.*.description'        => ['nullable', 'string', 'max:1000'],
            'rows.*.vendor_name'        => ['nullable', 'string', 'max:255'],
            'rows.*.activity_type'      => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'rows.required'                      => 'Tidak ada baris untuk diimport.',
            'rows.max'                           => 'Maksimal '.CarbonEntryImportService::MAX_ROWS.' baris per import.',
            'rows.*.entry_date.before_or_equal'  => 'Tanggal aktivitas tidak boleh di masa depan.',
            'rows.*.emission_factor_id.exists'   => 'Faktor emisi tidak ditemukan atau tidak aktif.',
            'rows.*.quantity.min'                => 'Jumlah harus lebih dari 0.',
        ];
    }
}
