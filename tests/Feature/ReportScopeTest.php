<?php

namespace Tests\Feature;

use App\Jobs\GenerateReportJob;
use App\Models\ReportJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsCarbonData;
use Tests\TestCase;

class ReportScopeTest extends TestCase
{
    use BuildsCarbonData, RefreshDatabase;

    public function test_admin_report_excludes_entries_of_deleted_projects(): void
    {
        Storage::fake('local');
        $this->seedMasterData();
        $owner = User::factory()->create();
        $this->makeApprovedEntry($this->makeProject($owner, 'TETAP'), $owner, 10);
        $deleted = $this->makeProject($owner, 'HAPUS');
        $this->makeApprovedEntry($deleted, $owner, 20);
        $deleted->delete();

        $reportJob = ReportJob::create(['user_id' => $this->admin->id, 'filters' => [], 'format' => 'csv', 'status' => 'pending']);
        GenerateReportJob::dispatchSync($reportJob);

        $rows = array_map('str_getcsv', file(Storage::disk('local')->path($reportJob->fresh()->file_path), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));

        // Sama dengan dashboard: entri dari project yang sudah dihapus tidak ikut dilaporkan
        $this->assertSame(['Project TETAP'], array_column(array_slice($rows, 1), 1));
    }
}
