<?php

namespace App\Services;

use App\Models\CarbonEntry;
use App\Models\CarbonTarget;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Collection;

class CarbonTargetService
{
    public function list(Project $project): Collection
    {
        return CarbonTarget::with('category')
            ->where('project_id', $project->id)
            ->orderBy('period_year', 'desc')
            ->orderBy('period_value', 'desc')
            ->get();
    }

    public function create(array $data, Project $project, User $creator): CarbonTarget
    {
        return CarbonTarget::create([
            'project_id'            => $project->id,
            'category_id'           => $data['category_id'] ?? null,
            'period_type'           => $data['period_type'],
            'period_year'           => $data['period_year'],
            'period_value'          => $data['period_value'] ?? null,
            'target_co2e_kg'        => $data['target_co2e_kg'],
            'baseline_co2e_kg'      => $data['baseline_co2e_kg'] ?? null,
            'reduction_percentage'  => $data['reduction_percentage'] ?? null,
            'notes'                 => $data['notes'] ?? null,
            'created_by'            => $creator->id,
        ]);
    }

    public function update(CarbonTarget $target, array $data, User $updater): CarbonTarget
    {
        $target->update([
            'category_id'           => $data['category_id'] ?? null,
            'period_type'           => $data['period_type'],
            'period_year'           => $data['period_year'],
            'period_value'          => $data['period_value'] ?? null,
            'target_co2e_kg'        => $data['target_co2e_kg'],
            'baseline_co2e_kg'      => $data['baseline_co2e_kg'] ?? null,
            'reduction_percentage'  => $data['reduction_percentage'] ?? null,
            'notes'                 => $data['notes'] ?? null,
            'updated_by'            => $updater->id,
        ]);

        return $target->fresh('category');
    }

    public function delete(CarbonTarget $target): void
    {
        $target->delete();
    }

    public function getProgress(Project $project): Collection
    {
        $targets = CarbonTarget::with('category')
            ->where('project_id', $project->id)
            ->get();

        return $targets->map(function (CarbonTarget $target) use ($project) {
            $actual = $this->getActualForTarget($target, $project);

            $percentage = $target->target_co2e_kg > 0
                ? round(($actual / $target->target_co2e_kg) * 100, 2)
                : 0;

            $remaining = max(0, $target->target_co2e_kg - $actual);

            return [
                'target_id'             => $target->id,
                'category'              => $target->category?->name ?? 'Semua Kategori',
                'period_type'           => $target->period_type,
                'period_year'           => $target->period_year,
                'period_value'          => $target->period_value,
                'target_co2e_kg'        => (float) $target->target_co2e_kg,
                'actual_co2e_kg'        => (float) $actual,
                'remaining_co2e_kg'     => (float) $remaining,
                'percentage_used'       => $percentage,
                'is_exceeded'           => $actual > $target->target_co2e_kg,
                'baseline_co2e_kg'      => $target->baseline_co2e_kg ? (float) $target->baseline_co2e_kg : null,
                'reduction_percentage'  => $target->reduction_percentage ? (float) $target->reduction_percentage : null,
            ];
        });
    }

    private function getActualForTarget(CarbonTarget $target, Project $project): float
    {
        $query = CarbonEntry::where('project_id', $project->id)
            ->where('status', 'approved')
            ->where('period_year', $target->period_year);

        if ($target->category_id) {
            $query->where('category_id', $target->category_id);
        }

        if ($target->period_type === 'monthly' && $target->period_value) {
            $query->where('period_month', $target->period_value);
        }

        if ($target->period_type === 'quarterly' && $target->period_value) {
            $startMonth = ($target->period_value - 1) * 3 + 1;
            $endMonth = $startMonth + 2;
            $query->whereBetween('period_month', [$startMonth, $endMonth]);
        }

        return (float) $query->sum('co2e_kg');
    }
}
