<?php

namespace App\Http\Requests\ReductionInitiative;

use App\Models\ReductionInitiative;

class UpdateReductionInitiativeRequest extends StoreReductionInitiativeRequest
{
    /** Hak akses project dulu (orang luar 403), lalu inisiatif harus ada di project ini (404 sebelum validasi) */
    public function authorize(): bool
    {
        if (! parent::authorize()) {
            return false;
        }

        ReductionInitiative::where('project_id', $this->route('projectId'))->findOrFail($this->route('id'));

        return true;
    }

    protected function keepsCurrentPic(int $userId): bool
    {
        $current = ReductionInitiative::where('project_id', $this->route('projectId'))
            ->whereKey($this->route('id'))
            ->value('pic_user_id');

        return $current !== null && (int) $current === $userId;
    }
}
