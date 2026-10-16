<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ReductionInitiative;
use App\Models\User;
use Illuminate\Support\Collection;

class ReductionInitiativeService
{
    /** @return Collection<int, ReductionInitiative> yang berjalan dulu, lalu rencana, selesai, batal */
    public function list(Project $project): Collection
    {
        return ReductionInitiative::with(['category', 'pic'])
            ->where('project_id', $project->id)
            ->orderByRaw("CASE status WHEN 'in_progress' THEN 0 WHEN 'planned' THEN 1 WHEN 'done' THEN 2 ELSE 3 END")
            // Target terdekat dulu; tanpa target tanggal di akhir
            ->orderByRaw('target_date IS NULL')
            ->orderBy('target_date')
            ->orderBy('id')
            ->get();
    }

    /**
     * Estimasi reduksi per tahun: yang masih direncanakan/berjalan dan yang sudah selesai.
     *
     * @param  Collection<int, ReductionInitiative>  $initiatives
     * @return array{planned_kg: float, done_kg: float}
     */
    public function totals(Collection $initiatives): array
    {
        $sum = fn (array $statuses) => round(
            $initiatives->whereIn('status', $statuses)->sum(fn (ReductionInitiative $i) => (float) $i->estimated_reduction_kg),
            4
        );

        return [
            'planned_kg' => $sum(ReductionInitiative::OPEN_STATUSES),
            'done_kg'    => $sum(['done']),
        ];
    }

    public function create(array $data, Project $project, User $creator): ReductionInitiative
    {
        $initiative = ReductionInitiative::create($this->attributes($data) + [
            'project_id' => $project->id,
            'created_by' => $creator->id,
        ]);

        return $initiative->load(['category', 'pic']);
    }

    public function update(ReductionInitiative $initiative, array $data, User $updater): ReductionInitiative
    {
        $initiative->update($this->attributes($data, $initiative) + ['updated_by' => $updater->id]);

        return $initiative->fresh(['category', 'pic']);
    }

    public function delete(ReductionInitiative $initiative): void
    {
        $initiative->delete();
    }

    private function attributes(array $data, ?ReductionInitiative $current = null): array
    {
        return [
            'title'                  => $data['title'],
            'description'            => $data['description'] ?? null,
            'category_id'            => $data['category_id'] ?? null,
            'estimated_reduction_kg' => $data['estimated_reduction_kg'],
            'status'                 => $data['status'],
            'pic_user_id'            => $data['pic_user_id'] ?? null,
            'target_date'            => $data['target_date'] ?? null,
            // Tanggal selesai dicatat saat pertama kali ditandai selesai, dihapus bila dibuka lagi
            'completed_at'           => match (true) {
                $data['status'] !== 'done'    => null,
                $current?->status === 'done' => $current->completed_at,
                default                      => now(),
            },
        ];
    }
}
