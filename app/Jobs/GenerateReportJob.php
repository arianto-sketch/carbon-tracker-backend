<?php

namespace App\Jobs;

use App\Exports\CarbonReportExport;
use App\Models\ReportJob;
use App\Services\ReportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

class GenerateReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 300;

    public function __construct(private ReportJob $reportJob) {}

    public function handle(ReportService $reportService): void
    {
        $this->reportJob->update(['status' => 'processing']);

        $filters = $this->reportJob->filters;
        $format = $this->reportJob->format;
        $allowedProjectIds = $reportService->allowedProjectIds($this->reportJob->user);

        $fileName = 'carbon-report-' . $this->reportJob->id . '-' . now()->format('Ymd_His') . '.' . $format;
        $filePath = 'reports/' . $fileName;

        // Error dibiarkan naik supaya queue bisa retry ($tries); status 'failed' diset di failed()
        Excel::store(
            new CarbonReportExport($filters, $allowedProjectIds, $format),
            $filePath,
            'local',
            $format === 'csv' ? \Maatwebsite\Excel\Excel::CSV : \Maatwebsite\Excel\Excel::XLSX
        );

        $this->reportJob->update([
            'status'       => 'done',
            'file_path'    => $filePath,
            'file_name'    => $fileName,
            'completed_at' => now(),
        ]);
    }

    public function failed(?Throwable $e): void
    {
        // Detail error sudah tercatat di log / failed_jobs; jangan bocorkan ke user
        $this->reportJob->update([
            'status'        => 'failed',
            'error_message' => 'Gagal membuat laporan. Silakan coba lagi atau hubungi administrator.',
            'completed_at'  => now(),
        ]);
    }
}
