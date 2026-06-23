<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CarbonEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                     => $this->id,
            'project_id'             => $this->project_id,
            'emission_factor'        => new EmissionFactorResource($this->whenLoaded('emissionFactor')),
            'category'               => new EmissionCategoryResource($this->whenLoaded('category')),
            'entry_date'             => $this->entry_date?->toDateString(),
            'period_month'           => $this->period_month,
            'period_year'            => $this->period_year,
            'quantity'               => (float) $this->quantity,
            'source_unit'            => $this->source_unit,
            'emission_factor_value'  => (float) $this->emission_factor_value,
            'co2e_kg'                => (float) $this->co2e_kg,
            'description'            => $this->description,
            'vendor_name'            => $this->vendor_name,
            'activity_type'          => $this->activity_type,
            'attachment_path'        => $this->attachment_path,
            'status'                 => $this->status,
            'approved_by'            => new UserResource($this->whenLoaded('approvedBy')),
            'approved_at'            => $this->approved_at?->toISOString(),
            'created_by'             => new UserResource($this->whenLoaded('createdBy')),
            'created_at'             => $this->created_at?->toISOString(),
            'updated_at'             => $this->updated_at?->toISOString(),
        ];
    }
}
