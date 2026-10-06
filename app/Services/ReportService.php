<?php

namespace App\Services;

use App\Jobs\GenerateReportJob;
use App\Models\ReportJob;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;

class ReportService
{
    public function generate(array $filters, User $user): ReportJob
    {
        $allowed = $this->allowedProjectIds($user);
        $requested = $filters['project_ids'] ?? [];

        if ($allowed !== null && array_diff($requested, $allowed)) {
            abort(403, 'Akses ditolak. Anda bukan member dari project yang diminta.');
        }

        $reportJob = ReportJob::create([
            'user_id' => $user->id,
            'filters' => $filters,
            'format'  => $filters['format'] ?? 'xlsx',
            'status'  => 'pending',
        ]);

        GenerateReportJob::dispatch($reportJob);

        return $reportJob;
    }

    public function getHistory(User $user, int $perPage = 15): LengthAwarePaginator
    {
        return ReportJob::where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    public function getJob(int $jobId, User $user): ReportJob
    {
        return ReportJob::where('user_id', $user->id)->findOrFail($jobId);
    }

    /**
     * Project yang boleh masuk laporan user ini. null = tanpa batasan (admin).
     */
    public function allowedProjectIds(?User $user): ?array
    {
        if ($user?->isAdmin()) {
            return null;
        }

        return $user ? $user->projects()->pluck('projects.id')->all() : [];
    }
}
