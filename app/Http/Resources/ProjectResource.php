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

    /** Role efektif (viewer global selalu "viewer"), dipakai frontend untuk menampilkan tombol kelola */
    private function currentUserRole(Request $request): ?string
    {
        $user = $request->user();

        if (! $user) {
            return null;
        }

        // List project: role sudah dihitung lewat subquery (ProjectService::list)
        if (array_key_exists('current_user_role', $this->resource->getAttributes())) {
            $role = $this->resource->getAttribute('current_user_role');
        } elseif ($this->relationLoaded('projectMembers')) {
            // Detail project sudah memuat projectMembers
            $role = $this->projectMembers->firstWhere('user_id', $user->id)?->role;
        } else {
            $role = $this->getUserRole($user->id);
        }

        return Project::effectiveRoleOf($user, $role);
    }
}
