<?php

namespace App\Services;

use App\Models\CarbonEntry;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/** Isi email ringkasan bulanan: emisi disetujui sebulan penuh di project yang dikelola penerima */
class MonthlySummaryService
{
    /** Project yang dirinci di email, diurutkan dari emisi terbesar */
    public const MAX_PROJECTS = 10;

    /** Target hampir/sudah terlampaui yang dirinci di email, dari pemakaian tertinggi */
    public const MAX_ALERTS = 10;

    private const MONTHS = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

    public function __construct(private CarbonTargetService $targets) {}

    /**
     * User aktif yang tidak mematikan ringkasan dan merupakan admin atau owner project yang belum
     * diarsipkan. Role global Viewer selalu hanya-baca, jadi tidak ikut walau tercatat sebagai owner.
     *
     * @return Builder<User>
     */
    public function recipients(): Builder
    {
        return User::query()
            ->where('is_active', true)
            ->where('monthly_summary_email', true)
            ->where('role', '!=', 'viewer')
            ->where(fn ($q) => $q->where('role', 'admin')
                ->orWhereHas('projectMembers', fn ($m) => $m->where('role', 'owner')
                    ->whereHas('project', fn ($p) => $p->where('status', '!=', 'archived'))));
    }

    /**
     * Ringkasan untuk satu penerima; null bila tidak ada project yang dikelolanya.
     *
     * @param  CarbonImmutable  $month  hari pertama bulan yang diringkas
     */
    public function build(User $user, CarbonImmutable $month): ?array
    {
        if ($user->isViewer()) {
            return null;
        }

        $projects = $this->managedProjects($user);
        if ($projects->isEmpty()) {
            return null;
        }

        $previous = $month->subMonthNoOverflow();
        $ids = $projects->modelKeys();
        $totals = $this->approvedTotals($ids, [$month, $previous]);

        $sum = fn (CarbonImmutable $m, ?int $projectId = null): float => round($totals
            ->filter(fn ($row) => (int) $row->period_year === $m->year
                && (int) $row->period_month === $m->month
                && ($projectId === null || (int) $row->project_id === $projectId))
            ->sum(fn ($row) => (float) $row->total_co2e_kg), 4);

        $rows = $projects
            ->map(function (Project $project) use ($sum, $month, $previous) {
                $current = $sum($month, $project->id);
                $before = $sum($previous, $project->id);

                return [
                    'id'                     => $project->id,
                    'name'                   => $project->name,
                    'code'                   => $project->code,
                    'total_co2e_kg'          => $current,
                    'previous_total_co2e_kg' => $before,
                    'change_pct'             => $this->change($current, $before),
                ];
            })
            ->sortByDesc('total_co2e_kg')
            ->values();

        $total = $sum($month);
        $previousTotal = $sum($previous);
        // Target tahun yang diringkas dengan pemakaian >= 80% (sudah urut tertinggi), hanya di project yang dikelola
        $alerts = collect($this->targets->getAlerts($user, $month->year))->whereIn('project_id', $ids)->values();

        return [
            'period'                 => $month->format('Y-m'),
            'year'                   => $month->year,
            'period_label'           => $this->label($month),
            'previous_label'         => $this->label($previous),
            'total_co2e_kg'          => $total,
            'previous_total_co2e_kg' => $previousTotal,
            'change_pct'             => $this->change($total, $previousTotal),
            'project_count'          => $rows->count(),
            'projects'               => $rows->take(self::MAX_PROJECTS)->all(),
            // Entri buatan sendiri tidak bisa di-review sendiri, jadi tidak dihitung
            'pending_review_count'   => CarbonEntry::whereIn('project_id', $ids)
                ->where('status', 'submitted')
                ->where('created_by', '!=', $user->id)
                ->count(),
            'alert_count'            => $alerts->count(),
            'target_alerts'          => $alerts->take(self::MAX_ALERTS)->all(),
        ];
    }

    /** @return EloquentCollection<int, Project> admin: semua project; owner: project miliknya. Tanpa yang diarsipkan. */
    private function managedProjects(User $user): EloquentCollection
    {
        return Project::query()
            ->where('status', '!=', 'archived')
            ->when(! $user->isAdmin(), fn ($q) => $q->whereHas('projectMembers', fn ($m) => $m
                ->where('user_id', $user->id)
                ->where('role', 'owner')))
            ->orderBy('name')
            ->get(['id', 'name', 'code']);
    }

    /**
     * Total emisi disetujui per project dan bulan dalam satu query.
     *
     * @param  array<int>  $projectIds
     * @param  array<CarbonImmutable>  $months
     */
    private function approvedTotals(array $projectIds, array $months): Collection
    {
        return CarbonEntry::whereIn('project_id', $projectIds)
            ->where('status', 'approved')
            ->where(function ($q) use ($months) {
                foreach ($months as $m) {
                    $q->orWhere(fn ($w) => $w->where('period_year', $m->year)->where('period_month', $m->month));
                }
            })
            ->selectRaw('project_id, period_year, period_month, SUM(co2e_kg) as total_co2e_kg')
            ->groupBy('project_id', 'period_year', 'period_month')
            ->toBase()
            ->get();
    }

    /** Tidak terdefinisi bila bulan pembanding nol */
    private function change(float $current, float $previous): ?float
    {
        return $previous > 0 ? round(($current - $previous) / $previous * 100, 2) : null;
    }

    private function label(CarbonImmutable $month): string
    {
        return self::MONTHS[$month->month - 1].' '.$month->year;
    }
}
