<?php

namespace App\Http\Resources;

use App\Models\ProjectMember;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProjectMember */
class ProjectMemberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user' => new UserResource($this->whenLoaded('user')),
            'role' => $this->role,
            'joined_at' => $this->joined_at->toISOString(),
        ];
    }
}
