<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(private DashboardService $service) {}

    public function summary(Request $request): JsonResponse
    {
        $data = $this->service->getSummary(
            $request->user(),
            $request->only(['period_year', 'period_month', 'project_id'])
        );

        return response()->json(['data' => $data]);
    }

    public function projects(Request $request): JsonResponse
    {
        $data = $this->service->getProjectsWithEmissions(
            $request->user(),
            $request->only(['period_year', 'period_month'])
        );

        return response()->json(['data' => $data]);
    }

    public function trend(Request $request): JsonResponse
    {
        $data = $this->service->getTrend(
            $request->user(),
            $request->only(['period_year', 'project_id'])
        );

        return response()->json(['data' => $data]);
    }

    public function categoryBreakdown(Request $request): JsonResponse
    {
        $data = $this->service->getCategoryBreakdown(
            $request->user(),
            $request->only(['period_year', 'period_month', 'project_id'])
        );

        return response()->json(['data' => $data]);
    }

    public function topEntries(Request $request): JsonResponse
    {
        $data = $this->service->getTopEntries(
            $request->user(),
            $request->only(['period_year', 'period_month', 'project_id']),
            (int) $request->get('limit', 5)
        );

        return response()->json(['data' => $data]);
    }
}
