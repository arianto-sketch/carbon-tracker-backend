<?php

namespace App\Http\Resources;

use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Project */
class ProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'description' => $this->description,
            'client_name' => $this->client_name,
            'status' => $this->status,
            'start_date' => $this->start_date->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'created_by' => new UserResource($this->whenLoaded('createdBy')),
            'members' => ProjectMemberResource::collection($this->whenLoaded('projectMembers')),
            // Role project milik user yang login (null kalau bukan member, mis. admin)
            'current_user_role' => $this->currentUserRole($request),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    private function currentUserRole(Request $request): ?string
    {
        $userId = $request->user()?->id;

        if (! $userId) {
            return null;
        }

        // List project: role sudah dihitung lewat subquery (ProjectService::list)
        if (array_key_exists('current_user_role', $this->resource->getAttributes())) {
            return $this->resource->getAttribute('current_user_role');
        }

        // Detail project sudah memuat projectMembers
        if ($this->relationLoaded('projectMembers')) {
            return $this->projectMembers->firstWhere('user_id', $userId)?->role;
        }

        return $this->getUserRole($userId);
    }
}
