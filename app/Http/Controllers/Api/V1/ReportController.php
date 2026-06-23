<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ReportController extends Controller
{
    public function __construct(private ReportService $service) {}

    public function generate(Request $request): JsonResponse
    {
        $request->validate([
            'project_ids'       => ['nullable', 'array'],
            'project_ids.*'     => ['integer', 'exists:projects,id'],
            'period_year'       => ['nullable', 'integer', 'min:2020', 'max:2100'],
            'period_month_from' => ['nullable', 'integer', 'min:1', 'max:12'],
            'period_month_to'   => ['nullable', 'integer', 'min:1', 'max:12'],
            'category_ids'      => ['nullable', 'array'],
            'category_ids.*'    => ['integer', 'exists:emission_categories,id'],
            'format'            => ['nullable', 'in:xlsx,csv'],
        ]);

        $reportJob = $this->service->generate($request->all(), $request->user());

        return response()->json([
            'data' => [
                'job_id' => $reportJob->id,
                'status' => $reportJob->status,
            ],
            'message' => 'Laporan sedang dibuat. Gunakan job_id untuk cek status.',
        ], 202);
    }

    public function jobStatus(Request $request, int $jobId): JsonResponse
    {
        $job = $this->service->getJob($jobId, $request->user());

        return response()->json([
            'data' => [
                'job_id'       => $job->id,
                'status'       => $job->status,
                'file_name'    => $job->file_name,
                'completed_at' => $job->completed_at?->toISOString(),
                'error'        => $job->error_message,
            ],
        ]);
    }

    public function download(Request $request, int $jobId)
    {
        $job = $this->service->getJob($jobId, $request->user());

        if (! $job->isDone() || ! $job->file_path) {
            return response()->json(['message' => 'File belum tersedia. Status: ' . $job->status], 400);
        }

        if (! Storage::disk('local')->exists($job->file_path)) {
            return response()->json(['message' => 'File tidak ditemukan atau sudah dihapus.'], 404);
        }

        return Storage::disk('local')->download($job->file_path, $job->file_name);
    }

    public function history(Request $request): JsonResponse
    {
        $jobs = $this->service->getHistory($request->user());

        return response()->json([
            'data' => $jobs->map(fn ($job) => [
                'job_id'       => $job->id,
                'format'       => $job->format,
                'status'       => $job->status,
                'file_name'    => $job->file_name,
                'created_at'   => $job->created_at?->toISOString(),
                'completed_at' => $job->completed_at?->toISOString(),
            ]),
            'meta' => [
                'current_page' => $jobs->currentPage(),
                'last_page'    => $jobs->lastPage(),
                'total'        => $jobs->total(),
            ],
        ]);
    }
}
