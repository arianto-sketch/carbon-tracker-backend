<?php

namespace App\Http\Resources;

use App\Models\EmissionFactor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EmissionFactor */
class EmissionFactorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'category' => new EmissionCategoryResource($this->whenLoaded('category')),
            'name' => $this->name,
            'slug' => $this->slug,
            'scope' => $this->scope,
            'description' => $this->description,
            'source_unit' => $this->source_unit,
            // Nilai yang berlaku hari ini (periode bisa mulai berlaku sebelum sinkron harian kolomnya)
            'factor_value' => (float) ($this->relationLoaded('values')
                ? $this->valueOn(now(config('app.business_timezone'))->toDateString())
                : $this->factor_value),
            'factor_unit' => $this->factor_unit,
            'source' => $this->source,
            'version' => $this->version,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
