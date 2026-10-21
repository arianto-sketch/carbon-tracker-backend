<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\DataCompleteness\DataGapDismissalRequest;
use App\Models\Project;
use App\Services\DataCompletenessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Kelengkapan data bulanan project dan penanda "tidak ada data bulan ini" */
class DataCompletenessController extends Controller
{
    public function __construct(private DataCompletenessService $service) {}

    public function show(Request $request, int $projectId): JsonResponse
    {
        $project = Project::findOrFail($projectId);

        if (! $request->user()->isAdmin() && ! $project->hasUser($request->user()->id)) {
            abort(403, 'Akses ditolak. Anda bukan member project ini.');
        }

        return response()->json(['data' => $this->service->grid($project)]);
    }

    public function dismiss(DataGapDismissalRequest $request, int $projectId): JsonResponse
    {
        $data = $request->validated();
        $this->service->dismiss(
            Project::findOrFail($projectId),
            (int) $data['period_year'],
            (int) $data['period_month'],
            isset($data['emission_factor_id']) ? (int) $data['emission_factor_id'] : null,
            $request->user(),
        );

        return response()->json(['message' => 'Ditandai tidak ada data bulan ini.'], 201);
    }

    public function undismiss(DataGapDismissalRequest $request, int $projectId): JsonResponse
    {
        $data = $request->validated();
        $this->service->undismiss(
            Project::findOrFail($projectId),
            (int) $data['period_year'],
            (int) $data['period_month'],
            isset($data['emission_factor_id']) ? (int) $data['emission_factor_id'] : null,
        );

        return response()->json(['message' => 'Tanda tidak ada data dibatalkan.']);
    }
}
