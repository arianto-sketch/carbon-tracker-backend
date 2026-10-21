<?php

namespace App\Services;

use App\Models\CarbonEntry;
use App\Models\DataGapDismissal;
use App\Models\EmissionFactor;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Kelengkapan data emisi per bulan. Sebuah faktor dianggap RUTIN di suatu bulan bila tercatat
 * (entri status apa pun) di minimal MIN_RECORDED_MONTHS dari HISTORY_MONTHS bulan sebelumnya;
 * faktor rutin yang tidak punya entri di bulan itu = belum diisi. Project aktif tanpa entri sama
 * sekali juga belum diisi. Keduanya bisa ditandai "tidak ada data bulan ini" (DataGapDismissal).
 * Dipakai panel project, kartu dashboard, dan pengingat tanggal 5/10 (definisi yang sama).
 */
class DataCompletenessService
{
    public const HISTORY_MONTHS = 3;

    public const MIN_RECORDED_MONTHS = 2;

    /** Jumlah bulan (yang sudah lewat) di panel kelengkapan project */
    public const GRID_MONTHS = 3;

    private const MONTHS = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

    /** Hari pertama bulan terakhir yang sudah lewat, menurut zona waktu bisnis */
    public function lastCompleteMonth(): CarbonImmutable
    {
        return CarbonImmutable::now(config('app.business_timezone'))->startOfMonth()->subMonthNoOverflow();
    }

    public function label(CarbonImmutable $month): string
    {
        return self::MONTHS[$month->month - 1].' '.$month->year;
    }

    /**
     * Project yang diharapkan punya data di bulan itu: aktif dan rentang tanggalnya mencakup bulan itu.
     * Dengan $user non-admin: hanya project tempat ia menjadi anggota.
     *
     * @return Builder<Project>
     */
    public function expectedProjects(CarbonImmutable $month, ?User $user = null): Builder
    {
        return Project::query()
            ->where('status', 'active')
            ->whereDate('start_date', '<=', $month->endOfMonth()->toDateString())
            ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $month->toDateString()))
            ->when($user && ! $user->isAdmin(), fn ($q) => $q->whereHas('projectMembers', fn ($m) => $m->where('user_id', $user->id)))
            ->orderBy('name')
            ->orderBy('id');
    }

    /**
     * Project yang datanya belum lengkap di $month, urut seperti $projects.
     *
     * @param  Collection<int, Project>  $projects
     * @return list<array{id: int, name: string, empty: bool, missing_factors: list<string>}>
     */
    public function gaps(Collection $projects, CarbonImmutable $month): array
    {
        if ($projects->isEmpty()) {
            return [];
        }

        $ids = $projects->pluck('id')->all();
        $records = $this->records($ids, $month->subMonthsNoOverflow(self::HISTORY_MONTHS), $month);
        $dismissed = $this->dismissals($ids, $month, $month);
        $ym = $this->ym($month);

        $gaps = [];
        foreach ($projects as $project) {
            $dismissedHere = $dismissed[$project->id] ?? [];
            // Seluruh project ditandai "tidak ada data": tidak ada yang diingatkan
            if (isset($dismissedHere[0][$ym])) {
                continue;
            }

            $factors = $records[$project->id] ?? [];
            $empty = collect($factors)->every(fn (array $months) => ! isset($months[$ym]));
            $missing = collect($factors)
                ->filter(fn (array $months, int $factorId) => ! isset($months[$ym])
                    && ! isset($dismissedHere[$factorId][$ym])
                    && $this->isRecurring($months, $month))
                ->keys()
                ->all();

            if ($empty || $missing !== []) {
                $gaps[] = ['id' => $project->id, 'name' => $project->name, 'empty' => $empty, 'missing_factor_ids' => $missing];
            }
        }

        $names = $this->factorNames(array_merge([], ...array_column($gaps, 'missing_factor_ids')));

        return array_map(fn (array $gap) => [
            'id' => $gap['id'],
            'name' => $gap['name'],
            'empty' => $gap['empty'],
            'missing_factors' => collect($gap['missing_factor_ids'])->map(fn (int $id) => $names[$id] ?? "#{$id}")->sort()->values()->all(),
        ], $gaps);
    }

    /**
     * Panel kelengkapan: GRID_MONTHS bulan terakhir yang sudah lewat x faktor yang tercatat atau rutin.
     * Status sel: recorded (diajukan/disetujui), draft (hanya draft/ditolak), dismissed, missing, none.
     */
    public function grid(Project $project): array
    {
        $last = $this->lastCompleteMonth();
        $months = collect(range(self::GRID_MONTHS - 1, 0))->map(fn (int $back) => $last->subMonthsNoOverflow($back));
        $first = $months->first();

        $records = $this->records([$project->id], $first->subMonthsNoOverflow(self::HISTORY_MONTHS), $last)[$project->id] ?? [];
        $dismissed = $this->dismissals([$project->id], $first, $last)[$project->id] ?? [];

        $status = function (int $factorId, CarbonImmutable $month) use ($records, $dismissed): string {
            $ym = $this->ym($month);

            return match (true) {
                isset($records[$factorId][$ym]) => $records[$factorId][$ym],
                isset($dismissed[$factorId][$ym]), isset($dismissed[0][$ym]) => 'dismissed',
                $this->isRecurring($records[$factorId] ?? [], $month) => 'missing',
                default => 'none',
            };
        };

        $names = $this->factorNames(array_keys($records));
        $rows = collect(array_keys($records))
            ->map(fn (int $factorId) => [
                'id' => $factorId,
                'name' => $names[$factorId] ?? "#{$factorId}",
                'statuses' => $months->map(fn (CarbonImmutable $m) => $status($factorId, $m))->all(),
            ])
            // Faktor yang tidak relevan di bulan-bulan yang ditampilkan (hanya tercatat sebelumnya) disembunyikan
            ->filter(fn (array $row) => collect($row['statuses'])->contains(fn (string $s) => $s !== 'none'))
            ->sortBy('name')
            ->values();

        return [
            'months' => $months->values()->map(function (CarbonImmutable $month, int $i) use ($project, $records, $dismissed, $rows) {
                $ym = $this->ym($month);
                $hasEntries = collect($records)->contains(fn (array $byMonth) => isset($byMonth[$ym]));
                $projectDismissed = isset($dismissed[0][$ym]);

                return [
                    'year' => $month->year,
                    'month' => $month->month,
                    'label' => $this->label($month),
                    'empty' => ! $hasEntries && ! $projectDismissed && $this->coversMonth($project, $month),
                    'dismissed' => $projectDismissed,
                    'missing_count' => $rows->filter(fn (array $row) => $row['statuses'][$i] === 'missing')->count(),
                ];
            })->all(),
            'factors' => $rows->all(),
        ];
    }

    /**
     * Entri draft/ditolak di $month per pembuat dan project (untuk pengingat ke pembuatnya).
     *
     * @param  array<int>  $projectIds
     * @return Collection<int, \stdClass> baris created_by, project_id, entry_count
     */
    public function unsubmitted(array $projectIds, CarbonImmutable $month): Collection
    {
        if ($projectIds === []) {
            return collect();
        }

        return CarbonEntry::whereIn('project_id', $projectIds)
            ->whereIn('status', ['draft', 'rejected'])
            ->where('period_year', $month->year)
            ->where('period_month', $month->month)
            ->selectRaw('created_by, project_id, COUNT(id) as entry_count')
            ->groupBy('created_by', 'project_id')
            ->toBase()
            ->get();
    }

    /** Tandai "tidak ada data bulan ini" (faktor null = seluruh project); aman diulang */
    public function dismiss(Project $project, int $year, int $month, ?int $factorId, User $user): DataGapDismissal
    {
        return DataGapDismissal::firstOrCreate(
            ['project_id' => $project->id, 'emission_factor_id' => $factorId, 'period_year' => $year, 'period_month' => $month],
            ['dismissed_by' => $user->id],
        );
    }

    public function undismiss(Project $project, int $year, int $month, ?int $factorId): void
    {
        DataGapDismissal::where('project_id', $project->id)
            ->where('emission_factor_id', $factorId)
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->delete();
    }

    /**
     * Status tercatat per project, faktor, dan bulan (Ym): 'recorded' bila ada entri diajukan/disetujui,
     * 'draft' bila hanya draft/ditolak. Satu query untuk semua project.
     *
     * @param  array<int>  $projectIds
     * @return array<int, array<int, array<int, string>>>
     */
    private function records(array $projectIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = CarbonEntry::whereIn('project_id', $projectIds)
            ->whereRaw('(period_year * 100 + period_month) between ? and ?', [$this->ym($from), $this->ym($to)])
            ->select(['project_id', 'emission_factor_id', 'period_year', 'period_month', 'status'])
            ->distinct()
            ->toBase()
            ->get();

        $records = [];
        foreach ($rows as $row) {
            $ym = (int) $row->period_year * 100 + (int) $row->period_month;
            $current = $records[$row->project_id][$row->emission_factor_id][$ym] ?? null;
            $records[$row->project_id][$row->emission_factor_id][$ym] =
                ($current === 'recorded' || in_array($row->status, ['submitted', 'approved'], true)) ? 'recorded' : 'draft';
        }

        return $records;
    }

    /**
     * @param  array<int>  $projectIds
     * @return array<int, array<int, array<int, true>>> [project][faktor, 0 = seluruh project][Ym]
     */
    private function dismissals(array $projectIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $map = [];
        DataGapDismissal::whereIn('project_id', $projectIds)
            ->whereRaw('(period_year * 100 + period_month) between ? and ?', [$this->ym($from), $this->ym($to)])
            ->get(['project_id', 'emission_factor_id', 'period_year', 'period_month'])
            ->each(function (DataGapDismissal $d) use (&$map) {
                $map[$d->project_id][$d->emission_factor_id ?? 0][$d->period_year * 100 + $d->period_month] = true;
            });

        return $map;
    }

    /** @param  array<int, string>  $months  tercatat per Ym untuk satu faktor */
    private function isRecurring(array $months, CarbonImmutable $month): bool
    {
        $recorded = 0;
        for ($back = 1; $back <= self::HISTORY_MONTHS; $back++) {
            if (isset($months[$this->ym($month->subMonthsNoOverflow($back))])) {
                $recorded++;
            }
        }

        return $recorded >= self::MIN_RECORDED_MONTHS;
    }

    private function coversMonth(Project $project, CarbonImmutable $month): bool
    {
        return $project->start_date->lte($month->endOfMonth())
            && ($project->end_date === null || $project->end_date->gte($month));
    }

    /**
     * @param  array<int>  $ids
     * @return array<int, string>
     */
    private function factorNames(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        /** @var EloquentCollection<int, EmissionFactor> $factors */
        $factors = EmissionFactor::whereIn('id', array_unique($ids))->get(['id', 'name']);

        return $factors->pluck('name', 'id')->all();
    }

    private function ym(CarbonImmutable $month): int
    {
        return $month->year * 100 + $month->month;
    }
}
