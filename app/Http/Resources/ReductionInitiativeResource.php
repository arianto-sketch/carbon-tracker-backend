<?php

namespace App\Http\Resources;

use App\Models\ReductionInitiative;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ReductionInitiative */
class ReductionInitiativeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                     => $this->id,
            'project_id'             => $this->project_id,
            'title'                  => $this->title,
            'description'            => $this->description,
            'category'               => new EmissionCategoryResource($this->whenLoaded('category')),
            'estimated_reduction_kg' => (float) $this->estimated_reduction_kg,
            'status'                 => $this->status,
            // Cukup id + nama: daftar ini juga dibaca viewer project
            'pic'                    => $this->whenLoaded('pic', fn () => $this->pic
                ? ['id' => $this->pic->id, 'name' => $this->pic->name]
                : null),
            'target_date'            => $this->target_date?->toDateString(),
            'completed_at'           => $this->completed_at?->toISOString(),
            'created_at'             => $this->created_at?->toISOString(),
            'updated_at'             => $this->updated_at?->toISOString(),
        ];
    }
}
