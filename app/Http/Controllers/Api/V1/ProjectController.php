<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Project\AddMemberRequest;
use App\Http\Requests\Project\StoreProjectRequest;
use App\Http\Requests\Project\UpdateProjectRequest;
use App\Http\Resources\ProjectMemberResource;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Services\ProjectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjectController extends Controller
{
    public function __construct(private ProjectService $projectService) {}

    public function index(Request $request): JsonResponse
    {
        $projects = $this->projectService->list($request->user());

        return response()->json([
            'data' => ProjectResource::collection($projects->items()),
            'meta' => [
                'current_page' => $projects->currentPage(),
                'last_page' => $projects->lastPage(),
                'per_page' => $projects->perPage(),
                'total' => $projects->total(),
            ],
        ]);
    }

    public function store(StoreProjectRequest $request): JsonResponse
    {
        $project = $this->projectService->create($request->validated(), $request->user());

        return response()->json([
            'data' => new ProjectResource($project),
            'message' => 'Project berhasil dibuat.',
        ], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $project = Project::with('createdBy', 'projectMembers.user')->findOrFail($id);

        if (! $request->user()->isAdmin() && ! $project->hasUser($request->user()->id)) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        return response()->json(['data' => new ProjectResource($project)]);
    }

    public function update(UpdateProjectRequest $request, int $id): JsonResponse
    {
        $project = Project::findOrFail($id);

        $userRole = $project->getUserRole($request->user()->id);
        if (! $request->user()->isAdmin() && $userRole !== 'owner') {
            return response()->json(['message' => 'Hanya owner atau admin yang bisa mengubah project.'], 403);
        }

        $project = $this->projectService->update($project, $request->validated(), $request->user());

        return response()->json([
            'data' => new ProjectResource($project),
            'message' => 'Project berhasil diperbarui.',
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        if (! $request->user()->isAdmin()) {
            return response()->json(['message' => 'Hanya admin yang bisa menghapus project.'], 403);
        }

        $project = Project::findOrFail($id);
        $this->projectService->delete($project);

        return response()->json(['message' => 'Project berhasil dihapus.']);
    }

    public function members(Request $request, int $id): JsonResponse
    {
        $project = Project::with('projectMembers.user')->findOrFail($id);

        if (! $request->user()->isAdmin() && ! $project->hasUser($request->user()->id)) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        return response()->json([
            'data' => ProjectMemberResource::collection($project->projectMembers),
        ]);
    }

    public function addMember(AddMemberRequest $request, int $id): JsonResponse
    {
        $project = Project::findOrFail($id);

        $userRole = $project->getUserRole($request->user()->id);
        if (! $request->user()->isAdmin() && $userRole !== 'owner') {
            return response()->json(['message' => 'Hanya owner atau admin yang bisa menambah member.'], 403);
        }

        $member = $this->projectService->addMember(
            $project,
            $request->user_id,
            $request->role ?? 'member'
        );

        return response()->json([
            'data' => new ProjectMemberResource($member->load('user')),
            'message' => 'Member berhasil ditambahkan.',
        ], 201);
    }

    public function updateMember(Request $request, int $id, int $userId): JsonResponse
    {
        $project = Project::findOrFail($id);

        $userRole = $project->getUserRole($request->user()->id);
        if (! $request->user()->isAdmin() && $userRole !== 'owner') {
            return response()->json(['message' => 'Hanya owner atau admin yang bisa mengubah role member.'], 403);
        }

        $request->validate(['role' => ['required', 'in:owner,member,viewer']]);

        $member = $this->projectService->updateMemberRole($project, $userId, $request->role);

        return response()->json([
            'data' => new ProjectMemberResource($member),
            'message' => 'Role member berhasil diperbarui.',
        ]);
    }

    public function removeMember(Request $request, int $id, int $userId): JsonResponse
    {
        $project = Project::findOrFail($id);

        $userRole = $project->getUserRole($request->user()->id);
        if (! $request->user()->isAdmin() && $userRole !== 'owner') {
            return response()->json(['message' => 'Hanya owner atau admin yang bisa menghapus member.'], 403);
        }

        $this->projectService->removeMember($project, $userId);

        return response()->json(['message' => 'Member berhasil dihapus dari project.']);
    }

    public function summary(Request $request, int $id): JsonResponse
    {
        $project = Project::findOrFail($id);

        if (! $request->user()->isAdmin() && ! $project->hasUser($request->user()->id)) {
            return response()->json(['message' => 'Akses ditolak.'], 403);
        }

        return response()->json([
            'data' => $this->projectService->getSummary($project),
        ]);
    }
}
