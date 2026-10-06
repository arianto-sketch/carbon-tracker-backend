<?php

namespace Tests\Feature;

use App\Exports\CarbonReportExport;
use App\Models\ReportJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use Tests\Concerns\BuildsCarbonData;
use Tests\TestCase;

class ReportAuthorizationTest extends TestCase
{
    use BuildsCarbonData, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMasterData();
        $this->freezeTime();
        Excel::fake();
    }

    /** Project IDs that would end up in the file generated for this report job. */
    private function exportedProjectIds(int $jobId): array
    {
        $job = ReportJob::findOrFail($jobId);
        $path = 'reports/carbon-report-'.$job->id.'-'.now()->format('Ymd_His').'.'.$job->format;

        $ids = null;
        Excel::assertStored($path, 'local', function (CarbonReportExport $export) use (&$ids) {
            $ids = $export->query()->pluck('project_id')->unique()->sort()->values()->all();

            return true;
        });

        return $ids;
    }

    public function test_user_without_projects_exports_nothing_when_no_filter_given(): void
    {
        $owner = User::factory()->create();
        $this->makeApprovedEntry($this->makeProject($owner), $owner);
        $outsider = User::factory()->viewer()->create();

        $jobId = $this->actingAs($outsider, 'sanctum')
            ->postJson('/api/v1/reports/generate', [])
            ->assertStatus(202)
            ->json('data.job_id');

        $this->assertSame([], $this->exportedProjectIds($jobId));
    }

    public function test_member_export_is_limited_to_own_projects(): void
    {
        $pm = User::factory()->create();
        $own = $this->makeProject($pm, 'OWN');
        $this->makeApprovedEntry($own, $pm);
        $stranger = User::factory()->create();
        $this->makeApprovedEntry($this->makeProject($stranger, 'FOREIGN'), $stranger);

        $jobId = $this->actingAs($pm, 'sanctum')
            ->postJson('/api/v1/reports/generate', [])
            ->assertStatus(202)
            ->json('data.job_id');

        $this->assertSame([$own->id], $this->exportedProjectIds($jobId));
    }

    public function test_requesting_a_foreign_project_is_forbidden(): void
    {
        $stranger = User::factory()->create();
        $foreign = $this->makeProject($stranger, 'FOREIGN');
        $pm = User::factory()->create();

        $this->actingAs($pm, 'sanctum')
            ->postJson('/api/v1/reports/generate', ['project_ids' => [$foreign->id]])
            ->assertForbidden();
    }

    public function test_admin_exports_all_projects(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $pa = $this->makeProject($a, 'A');
        $pb = $this->makeProject($b, 'B');
        $this->makeApprovedEntry($pa, $a);
        $this->makeApprovedEntry($pb, $b);

        $jobId = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/reports/generate', [])
            ->assertStatus(202)
            ->json('data.job_id');

        $this->assertSame([$pa->id, $pb->id], $this->exportedProjectIds($jobId));
    }

    public function test_only_validated_filters_are_stored(): void
    {
        $pm = User::factory()->create();

        $jobId = $this->actingAs($pm, 'sanctum')
            ->postJson('/api/v1/reports/generate', ['format' => 'csv', 'evil' => str_repeat('x', 50)])
            ->json('data.job_id');

        $this->assertArrayNotHasKey('evil', ReportJob::findOrFail($jobId)->filters);
    }
}
