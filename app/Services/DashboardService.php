<?php

namespace App\Services;

use App\Models\CarbonEntry;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    public function getSummary(User $user, array $filters = []): array
    {
        $query = CarbonEntry::where('status', 'approved');

        $this->applyUserScope($query, $user);
        $this->applyFilters($query, $filters);

        $total = (float) $query->sum('co2e_kg');
        $count = $query->count();

        return [
            'total_co2e_kg' => $total,
            'entry_count'   => $count,
            'period'        => $this->buildPeriodLabel($filters),
        ];
    }

    public function getProjectsWithEmissions(User $user, array $filters = []): array
    {
        $projectQuery = Project::query();

        if (! $user->isAdmin()) {
            $projectQuery->whereHas('projectMembers', fn ($q) => $q->where('user_id', $user->id));
        }

        $projects = $projectQuery->with('createdBy')->get();

        return $projects->map(function (Project $project) use ($filters) {
            $query = CarbonEntry::where('project_id', $project->id)->where('status', 'approved');
            $this->applyFilters($query, $filters);

            return [
                'id'            => $project->id,
                'name'          => $project->name,
                'code'          => $project->code,
                'status'        => $project->status,
                'total_co2e_kg' => (float) $query->sum('co2e_kg'),
                'entry_count'   => $query->count(),
            ];
        })->sortByDesc('total_co2e_kg')->values()->toArray();
    }

    public function getTrend(User $user, array $filters = []): array
    {
        $year = $filters['period_year'] ?? now()->year;

        $query = CarbonEntry::where('status', 'approved')
            ->where('period_year', $year)
            ->selectRaw('period_month, SUM(co2e_kg) as total_co2e_kg, COUNT(id) as entry_count')
            ->groupBy('period_month')
            ->orderBy('period_month');

        $this->applyUserScope($query, $user);

        if (isset($filters['project_id'])) {
            $query->where('project_id', $filters['project_id']);
        }

        $rows = $query->get()->keyBy('period_month');

        // Pastikan semua 12 bulan muncul meski data 0
        return collect(range(1, 12))->map(fn ($month) => [
            'month'         => $month,
            'month_label'   => $this->monthLabel($month),
            'total_co2e_kg' => (float) ($rows[$month]?->total_co2e_kg ?? 0),
            'entry_count'   => (int) ($rows[$month]?->entry_count ?? 0),
        ])->values()->toArray();
    }

    public function getCategoryBreakdown(User $user, array $filters = []): array
    {
        $query = CarbonEntry::where('status', 'approved')
            ->selectRaw('category_id, SUM(co2e_kg) as total_co2e_kg, COUNT(id) as entry_count')
            ->groupBy('category_id')
            ->with('category');

        $this->applyUserScope($query, $user);
        $this->applyFilters($query, $filters);

        $rows = $query->get();
        $grandTotal = $rows->sum('total_co2e_kg');

        return $rows->map(fn ($row) => [
            'category_id'   => $row->category_id,
            'category_name' => $row->category?->name ?? 'Unknown',
            'category_slug' => $row->category?->slug ?? '-',
            'total_co2e_kg' => (float) $row->total_co2e_kg,
            'entry_count'   => (int) $row->entry_count,
            'percentage'    => $grandTotal > 0
                ? round(($row->total_co2e_kg / $grandTotal) * 100, 2)
                : 0,
        ])->sortByDesc('total_co2e_kg')->values()->toArray();
    }

    public function getTopEntries(User $user, array $filters = [], int $limit = 5): array
    {
        $query = CarbonEntry::where('status', 'approved')
            ->with(['project', 'category', 'emissionFactor'])
            ->orderByDesc('co2e_kg')
            ->limit($limit);

        $this->applyUserScope($query, $user);
        $this->applyFilters($query, $filters);

        return $query->get()->map(fn ($entry) => [
            'id'            => $entry->id,
            'project_name'  => $entry->project?->name,
            'category_name' => $entry->category?->name,
            'factor_name'   => $entry->emissionFactor?->name,
            'quantity'      => (float) $entry->quantity,
            'source_unit'   => $entry->source_unit,
            'co2e_kg'       => (float) $entry->co2e_kg,
            'entry_date'    => $entry->entry_date?->toDateString(),
        ])->toArray();
    }

    private function applyUserScope($query, User $user): void
    {
        if (! $user->isAdmin()) {
            $projectIds = $user->projects()->pluck('projects.id');
            $query->whereIn('project_id', $projectIds);
        }
    }

    private function applyFilters($query, array $filters): void
    {
        if (isset($filters['period_year'])) {
            $query->where('period_year', $filters['period_year']);
        }

        if (isset($filters['period_month'])) {
            $query->where('period_month', $filters['period_month']);
        }

        if (isset($filters['project_id'])) {
            $query->where('project_id', $filters['project_id']);
        }
    }

    private function buildPeriodLabel(array $filters): string
    {
        $year = $filters['period_year'] ?? now()->year;
        $month = $filters['period_month'] ?? null;

        if ($month) {
            return $this->monthLabel($month) . ' ' . $year;
        }

        return 'YTD ' . $year;
    }

    private function monthLabel(int $month): string
    {
        return ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Ags','Sep','Okt','Nov','Des'][$month - 1];
    }
}
