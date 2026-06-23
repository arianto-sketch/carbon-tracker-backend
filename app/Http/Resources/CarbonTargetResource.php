<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CarbonTargetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                    => $this->id,
            'project_id'            => $this->project_id,
            'category'              => new EmissionCategoryResource($this->whenLoaded('category')),
            'period_type'           => $this->period_type,
            'period_year'           => $this->period_year,
            'period_value'          => $this->period_value,
            'target_co2e_kg'        => (float) $this->target_co2e_kg,
            'baseline_co2e_kg'      => $this->baseline_co2e_kg ? (float) $this->baseline_co2e_kg : null,
            'reduction_percentage'  => $this->reduction_percentage ? (float) $this->reduction_percentage : null,
            'notes'                 => $this->notes,
            'created_at'            => $this->created_at?->toISOString(),
        ];
    }
}
