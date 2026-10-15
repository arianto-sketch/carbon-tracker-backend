<?php

namespace App\Http\Requests\CarbonEntry;

use Illuminate\Foundation\Http\FormRequest;

/** Approve/tolak massal: hak owner/admin dicek di controller (role per project) */
class BulkReviewEntriesRequest extends FormRequest
{
    public const MAX_ENTRIES = 100;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'action'       => ['required', 'in:approve,reject'],
            'entry_ids'    => ['required', 'array', 'min:1', 'max:'.self::MAX_ENTRIES],
            'entry_ids.*'  => ['integer', 'distinct'],
            // Sama dengan tolak satu entri
            'reason'       => ['required_if:action,reject', 'nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'entry_ids.required' => 'Pilih minimal satu entri.',
            'entry_ids.min'      => 'Pilih minimal satu entri.',
            'entry_ids.max'      => 'Maksimal '.self::MAX_ENTRIES.' entri sekaligus.',
            'reason.required_if' => 'Alasan penolakan wajib diisi.',
            'reason.max'         => 'Alasan penolakan maksimal 500 karakter.',
        ];
    }
}
