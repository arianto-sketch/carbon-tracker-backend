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
}
