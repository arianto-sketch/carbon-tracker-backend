<?php

namespace Tests\Feature;

use App\Jobs\GenerateReportJob;
use App\Models\ReportJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

class GenerateReportJobTest extends TestCase
{
    use RefreshDatabase;

    private function pendingJob(): ReportJob
    {
        return ReportJob::create([
            'user_id' => User::factory()->create()->id,
            'filters' => [],
            'format' => 'xlsx',
            'status' => 'pending',
        ]);
    }

    public function test_successful_run_marks_job_done(): void
    {
        Excel::fake();
        $reportJob = $this->pendingJob();

        GenerateReportJob::dispatchSync($reportJob);

        $this->assertSame('done', $reportJob->fresh()->status);
    }

    public function test_php_error_marks_job_failed_instead_of_stuck_processing(): void
    {
        Excel::shouldReceive('store')->andThrow(new \Error('disk exploded at /var/secret/path'));
        $reportJob = $this->pendingJob();

        try {
            GenerateReportJob::dispatchSync($reportJob);
        } catch (\Throwable) {
            // sync queue melempar ulang error setelah memanggil failed()
        }

        $fresh = $reportJob->fresh();
        $this->assertSame('failed', $fresh->status);
        $this->assertNotNull($fresh->completed_at);
        $this->assertStringNotContainsString('/var/secret/path', (string) $fresh->error_message);
    }

    public function test_exception_is_rethrown_so_the_queue_can_retry(): void
    {
        Excel::shouldReceive('store')->andThrow(new \RuntimeException('transient'));

        $this->expectException(\RuntimeException::class);

        GenerateReportJob::dispatchSync($this->pendingJob());
    }
}
