<?php

namespace App\Http\Requests\ReductionInitiative;

use App\Models\ReductionInitiative;

class UpdateReductionInitiativeRequest extends StoreReductionInitiativeRequest
{
    protected function keepsCurrentPic(int $userId): bool
    {
        $current = ReductionInitiative::where('project_id', $this->route('projectId'))
            ->whereKey($this->route('id'))
            ->value('pic_user_id');

        return $current !== null && (int) $current === $userId;
    }
}
