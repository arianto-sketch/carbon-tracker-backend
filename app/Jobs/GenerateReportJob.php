<?php

namespace App\Jobs;

use App\Exports\CarbonReportExport;
use App\Models\ReportJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class GenerateReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 300;

    public function __construct(private ReportJob $reportJob) {}

    public function handle(): void
    {
        $this->reportJob->update(['status' => 'processing']);

        try {
            $filters = $this->reportJob->filters;
            $format = $this->reportJob->format;

            $fileName = 'carbon-report-' . $this->reportJob->id . '-' . now()->format('Ymd_His') . '.' . $format;
            $filePath = 'reports/' . $fileName;

            Excel::store(
                new CarbonReportExport($filters),
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
        } catch (\Exception $e) {
            $this->reportJob->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at'  => now(),
            ]);
        }
    }
}
