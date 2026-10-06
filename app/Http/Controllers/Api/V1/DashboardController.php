<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\CarbonTargetService;
use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class DashboardController extends Controller
{
    public function __construct(private DashboardService $service) {}

    public function summary(Request $request): JsonResponse
    {
        $data = $this->service->getSummary(
            $request->user(),
            $this->filters($request, ['period_year', 'period_month', 'project_id'])
        );

        return response()->json(['data' => $data]);
    }

    public function projects(Request $request): JsonResponse
    {
        $data = $this->service->getProjectsWithEmissions(
            $request->user(),
            $this->filters($request, ['period_year', 'period_month'])
        );

        return response()->json(['data' => $data]);
    }

    public function trend(Request $request): JsonResponse
    {
        $data = $this->service->getTrend(
            $request->user(),
            $this->filters($request, ['period_year', 'project_id'])
        );

        return response()->json(['data' => $data]);
    }

    public function categoryBreakdown(Request $request): JsonResponse
    {
        $data = $this->service->getCategoryBreakdown(
            $request->user(),
            $this->filters($request, ['period_year', 'period_month', 'project_id'])
        );

        return response()->json(['data' => $data]);
    }

    public function topEntries(Request $request): JsonResponse
    {
        $filters = $this->filters($request, ['period_year', 'period_month', 'project_id', 'limit']);

        $data = $this->service->getTopEntries(
            $request->user(),
            Arr::except($filters, 'limit'),
            (int) ($filters['limit'] ?? 5)
        );

        return response()->json(['data' => $data]);
    }

    public function targetAlerts(Request $request, CarbonTargetService $targets): JsonResponse
    {
        $year = now(config('app.business_timezone'))->year;

        return response()->json(['data' => $targets->getAlerts($request->user(), $year)]);
    }

    private function filters(Request $request, array $keys): array
    {
        $rules = [
            'period_year'  => ['nullable', 'integer', 'min:2020', 'max:2100'],
            'period_month' => ['nullable', 'integer', 'min:1', 'max:12'],
            'project_id'   => ['nullable', 'integer'],
            'limit'        => ['nullable', 'integer', 'min:1', 'max:50'],
        ];

        $validated = $request->validate(Arr::only($rules, $keys));

        return array_filter($validated, fn ($value) => $value !== null);
    }
}
