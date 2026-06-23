<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

class ProjectService
{
    public function list(User $user, int $perPage = 15): LengthAwarePaginator
    {
        $query = Project::with('createdBy');

        if (! $user->isAdmin()) {
            $query->whereHas('projectMembers', fn ($q) => $q->where('user_id', $user->id));
        }

        return $query->orderByDesc('created_at')->paginate($perPage);
    }

    public function create(array $data, User $creator): Project
    {
        $project = Project::create([
            'name' => $data['name'],
            'code' => strtoupper($data['code']),
            'description' => $data['description'] ?? null,
            'client_name' => $data['client_name'] ?? null,
            'status' => $data['status'] ?? 'active',
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'] ?? null,
            'created_by' => $creator->id,
        ]);

        // Creator otomatis jadi owner
        ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $creator->id,
            'role' => 'owner',
        ]);

        return $project->load('createdBy', 'projectMembers.user');
    }

    public function update(Project $project, array $data, User $updater): Project
    {
        $project->update([
            'name' => $data['name'],
            'code' => strtoupper($data['code']),
            'description' => $data['description'] ?? null,
            'client_name' => $data['client_name'] ?? null,
            'status' => $data['status'] ?? $project->status,
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'] ?? null,
            'updated_by' => $updater->id,
        ]);

        return $project->fresh(['createdBy', 'projectMembers.user']);
    }

    public function delete(Project $project): void
    {
        $project->delete();
    }

    public function addMember(Project $project, int $userId, string $role = 'member'): ProjectMember
    {
        if ($project->hasUser($userId)) {
            throw ValidationException::withMessages([
                'user_id' => ['User sudah menjadi member project ini.'],
            ]);
        }

        return ProjectMember::create([
            'project_id' => $project->id,
            'user_id' => $userId,
            'role' => $role,
        ]);
    }

    public function updateMemberRole(Project $project, int $userId, string $role): ProjectMember
    {
        $member = ProjectMember::where('project_id', $project->id)
            ->where('user_id', $userId)
            ->firstOrFail();

        $member->update(['role' => $role]);

        return $member->fresh('user');
    }

    public function removeMember(Project $project, int $userId): void
    {
        $member = ProjectMember::where('project_id', $project->id)
            ->where('user_id', $userId)
            ->firstOrFail();

        if ($member->role === 'owner') {
            throw ValidationException::withMessages([
                'user_id' => ['Owner project tidak bisa dihapus dari member.'],
            ]);
        }

        $member->delete();
    }

    public function getSummary(Project $project): array
    {
        $entries = $project->carbonEntries()->where('status', 'approved');

        return [
            'total_co2e_kg' => (float) $entries->sum('co2e_kg'),
            'entry_count' => $entries->count(),
            'by_category' => $entries->with('category')
                ->selectRaw('category_id, SUM(co2e_kg) as total_co2e_kg, COUNT(id) as count')
                ->groupBy('category_id')
                ->get()
                ->map(fn ($row) => [
                    'category_id' => $row->category_id,
                    'category_name' => $row->category?->name,
                    'total_co2e_kg' => (float) $row->total_co2e_kg,
                    'count' => $row->count,
                ]),
        ];
    }
}
