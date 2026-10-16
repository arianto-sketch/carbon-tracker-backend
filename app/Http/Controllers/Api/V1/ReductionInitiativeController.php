<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReductionInitiative\StoreReductionInitiativeRequest;
use App\Http\Requests\ReductionInitiative\UpdateReductionInitiativeRequest;
use App\Http\Resources\ReductionInitiativeResource;
use App\Models\Project;
use App\Models\ReductionInitiative;
use App\Services\ReductionInitiativeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Rencana aksi reduksi: dibaca semua anggota, dikelola owner/member (lihat request), dihapus owner/admin */
class ReductionInitiativeController extends Controller
{
    public function __construct(private ReductionInitiativeService $service) {}

    public function index(Request $request, int $projectId): JsonResponse
    {
        $project = Project::findOrFail($projectId);

        if (! $request->user()->isAdmin() && ! $project->hasUser($request->user()->id)) {
            abort(403, 'Akses ditolak. Anda bukan member project ini.');
        }

        $initiatives = $this->service->list($project);

        return response()->json([
            'data' => ReductionInitiativeResource::collection($initiatives),
            'meta' => $this->service->totals($initiatives),
        ]);
    }

    public function store(StoreReductionInitiativeRequest $request, int $projectId): JsonResponse
    {
        $project = Project::findOrFail($projectId);
        $initiative = $this->service->create($request->validated(), $project, $request->user());

        return response()->json([
            'data'    => new ReductionInitiativeResource($initiative),
            'message' => 'Inisiatif berhasil ditambahkan.',
        ], 201);
    }

    public function update(UpdateReductionInitiativeRequest $request, int $projectId, int $id): JsonResponse
    {
        $initiative = ReductionInitiative::where('project_id', $projectId)->findOrFail($id);
        $initiative = $this->service->update($initiative, $request->validated(), $request->user());

        return response()->json([
            'data'    => new ReductionInitiativeResource($initiative),
            'message' => 'Inisiatif berhasil diperbarui.',
        ]);
    }

    public function destroy(Request $request, int $projectId, int $id): JsonResponse
    {
        $project = Project::findOrFail($projectId);

        if (! $request->user()->isAdmin() && $project->effectiveRole($request->user()) !== 'owner') {
            abort(403, 'Hanya owner atau admin yang bisa menghapus inisiatif.');
        }

        $initiative = ReductionInitiative::where('project_id', $projectId)->findOrFail($id);
        $this->service->delete($initiative);

        return response()->json(['message' => 'Inisiatif berhasil dihapus.']);
    }
}
