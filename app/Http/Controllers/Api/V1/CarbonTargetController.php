<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CarbonTarget\StoreCarbonTargetRequest;
use App\Http\Requests\CarbonTarget\UpdateCarbonTargetRequest;
use App\Http\Resources\CarbonTargetResource;
use App\Models\CarbonTarget;
use App\Models\Project;
use App\Services\CarbonTargetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CarbonTargetController extends Controller
{
    public function __construct(private CarbonTargetService $service) {}

    public function index(Request $request, int $projectId): JsonResponse
    {
        $project = Project::findOrFail($projectId);
        $this->authorizeProjectAccess($request, $project);

        $targets = $this->service->list($project);

        return response()->json([
            'data' => CarbonTargetResource::collection($targets),
        ]);
    }

    public function store(StoreCarbonTargetRequest $request, int $projectId): JsonResponse
    {
        $project = Project::findOrFail($projectId);
        $this->authorizeOwnerOrAdmin($request, $project);

        $target = $this->service->create($request->validated(), $project, $request->user());

        return response()->json([
            'data'    => new CarbonTargetResource($target->load('category')),
            'message' => 'Target emisi berhasil dibuat.',
        ], 201);
    }

    public function show(Request $request, int $projectId, int $id): JsonResponse
    {
        $project = Project::findOrFail($projectId);
        $this->authorizeProjectAccess($request, $project);

        $target = CarbonTarget::with('category')
            ->where('project_id', $projectId)
            ->findOrFail($id);

        return response()->json(['data' => new CarbonTargetResource($target)]);
    }

    public function update(UpdateCarbonTargetRequest $request, int $projectId, int $id): JsonResponse
    {
        $project = Project::findOrFail($projectId);
        $this->authorizeOwnerOrAdmin($request, $project);

        $target = CarbonTarget::where('project_id', $projectId)->findOrFail($id);
        $target = $this->service->update($target, $request->validated(), $request->user());

        return response()->json([
            'data'    => new CarbonTargetResource($target),
            'message' => 'Target emisi berhasil diperbarui.',
        ]);
    }

    public function destroy(Request $request, int $projectId, int $id): JsonResponse
    {
        $project = Project::findOrFail($projectId);
        $this->authorizeOwnerOrAdmin($request, $project);

        $target = CarbonTarget::where('project_id', $projectId)->findOrFail($id);
        $this->service->delete($target);

        return response()->json(['message' => 'Target emisi berhasil dihapus.']);
    }

    public function progress(Request $request, int $projectId): JsonResponse
    {
        $project = Project::findOrFail($projectId);
        $this->authorizeProjectAccess($request, $project);

        return response()->json([
            'data' => $this->service->getProgress($project),
        ]);
    }

    private function authorizeProjectAccess(Request $request, Project $project): void
    {
        if (! $request->user()->isAdmin() && ! $project->hasUser($request->user()->id)) {
            abort(403, 'Akses ditolak. Anda bukan member project ini.');
        }
    }

    private function authorizeOwnerOrAdmin(Request $request, Project $project): void
    {
        $userRole = $project->getUserRole($request->user()->id);
        if (! $request->user()->isAdmin() && $userRole !== 'owner') {
            abort(403, 'Hanya owner atau admin yang bisa mengelola target emisi.');
        }
    }
}
