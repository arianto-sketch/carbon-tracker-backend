<?php

namespace App\Services;

use App\Models\CarbonEntry;
use App\Models\CarbonTarget;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

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
        $this->ensureUniquePeriod($data, $project);

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
        $this->ensureUniquePeriod($data, $target->project, $target->id);

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

    /**
     * Target tahun $year di project yang bisa diakses user dengan pemakaian >= $threshold %,
     * urut dari pemakaian tertinggi. level: exceeded (>= 100%) atau warning.
     */
    public function getAlerts(User $user, int $year, float $threshold = 80): array
    {
        $projects = Project::query()
            ->when(! $user->isAdmin(), fn ($q) => $q->whereHas('projectMembers', fn ($m) => $m->where('user_id', $user->id)))
            ->whereHas('carbonTargets', fn ($q) => $q->where('period_year', $year))
            ->get();

        return $projects
            ->flatMap(fn (Project $project) => $this->getProgress($project)
                ->filter(fn (array $p) => (int) $p['period_year'] === $year && $p['percentage_used'] >= $threshold)
                ->map(fn (array $p) => $p + [
                    'project_id'   => $project->id,
                    'project_name' => $project->name,
                    'level'        => $p['percentage_used'] >= 100 ? 'exceeded' : 'warning',
                ]))
            ->sortByDesc('percentage_used')
            ->values()
            ->all();
    }

    public function getProgress(Project $project): Collection
    {
        $targets = CarbonTarget::with('category')
            ->where('project_id', $project->id)
            ->get();

        $totals = $this->approvedTotalsByMonth($project, $targets->pluck('period_year')->unique()->values()->all());

        return $targets->map(function (CarbonTarget $target) use ($totals) {
            $actual = $this->getActualForTarget($target, $totals);

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

    /**
     * Satu target aktif per project + kategori + periode. Dicek di aplikasi karena
     * unique index DB tidak bisa menangani kolom NULL dan baris soft-deleted.
     */
    private function ensureUniquePeriod(array $data, Project $project, ?int $ignoreId = null): void
    {
        $categoryId = $data['category_id'] ?? null;
        $periodValue = $data['period_value'] ?? null;

        $exists = CarbonTarget::where('project_id', $project->id)
            ->where('period_type', $data['period_type'])
            ->where('period_year', $data['period_year'])
            ->when($categoryId, fn ($q) => $q->where('category_id', $categoryId), fn ($q) => $q->whereNull('category_id'))
            ->when($periodValue, fn ($q) => $q->where('period_value', $periodValue), fn ($q) => $q->whereNull('period_value'))
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'period_type' => ['Target untuk kategori dan periode ini sudah ada.'],
            ]);
        }
    }

    /**
     * Total emisi approved project per kategori + tahun + bulan dalam satu query, supaya
     * progress semua target dihitung tanpa 1 query SUM per target.
     *
     * @param  array<int>  $years
     */
    private function approvedTotalsByMonth(Project $project, array $years): Collection
    {
        if ($years === []) {
            return collect();
        }

        return CarbonEntry::where('project_id', $project->id)
            ->where('status', 'approved')
            ->whereIn('period_year', $years)
            ->selectRaw('category_id, period_year, period_month, SUM(co2e_kg) as total_co2e_kg')
            ->groupBy('category_id', 'period_year', 'period_month')
            ->toBase()
            ->get();
    }

    private function getActualForTarget(CarbonTarget $target, Collection $totals): float
    {
        [$fromMonth, $toMonth] = match (true) {
            $target->period_type === 'monthly' && $target->period_value => [$target->period_value, $target->period_value],
            $target->period_type === 'quarterly' && $target->period_value => [($target->period_value - 1) * 3 + 1, $target->period_value * 3],
            default => [1, 12],
        };

        $sum = $totals
            ->filter(fn ($row) => (int) $row->period_year === (int) $target->period_year
                && (! $target->category_id || (int) $row->category_id === (int) $target->category_id)
                && $row->period_month >= $fromMonth && $row->period_month <= $toMonth)
            ->sum(fn ($row) => (float) $row->total_co2e_kg);

        // Dijumlah di PHP: dibulatkan ke presisi kolom co2e_kg (4 desimal) agar sama dengan SUM di database
        return round($sum, 4);
    }
}
