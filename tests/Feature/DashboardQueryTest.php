<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Services\DashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsCarbonData;
use Tests\TestCase;

class DashboardQueryTest extends TestCase
{
    use BuildsCarbonData, RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMasterData();
        $this->owner = User::factory()->create();
    }

    private function projectWithEmission(string $code, float $quantity): Project
    {
        $project = $this->makeProject($this->owner, $code);
        $this->makeApprovedEntry($project, $this->owner, $quantity, '2026-03-15');
        $this->makeEntry($project, $this->owner, 'draft');

        return $project;
    }

    private function countQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    public function test_project_emissions_use_a_constant_number_of_queries(): void
    {
        $service = app(DashboardService::class);
        $this->projectWithEmission('P-1', 10);
        $one = $this->countQueries(fn () => $service->getProjectsWithEmissions($this->admin));

        foreach (range(2, 6) as $i) {
            $this->projectWithEmission("P-{$i}", $i * 10);
        }
        $six = $this->countQueries(fn () => $service->getProjectsWithEmissions($this->admin));

        $this->assertSame($one, $six);
    }

    public function test_project_emissions_only_count_approved_entries_within_filters(): void
    {
        $this->projectWithEmission('P-1', 10);
        $big = $this->projectWithEmission('P-2', 100);
        $this->makeApprovedEntry($big, $this->owner, 50, '2025-06-01');

        $rows = collect(app(DashboardService::class)->getProjectsWithEmissions($this->admin, ['period_year' => 2026]));

        $this->assertSame(['P-2', 'P-1'], $rows->pluck('code')->all());
        $this->assertEqualsWithDelta(231.0, $rows[0]['total_co2e_kg'], 0.001);
        $this->assertSame(1, $rows[0]['entry_count']);
    }

    public function test_project_emissions_respect_project_filter_and_deleted_entries(): void
    {
        $this->projectWithEmission('P-1', 10);
        $second = $this->projectWithEmission('P-2', 100);
        $this->makeApprovedEntry($second, $this->owner, 1000, '2026-03-20')->delete();

        $rows = collect(app(DashboardService::class)->getProjectsWithEmissions($this->admin, ['project_id' => $second->id]))->keyBy('code');

        $this->assertEqualsWithDelta(231.0, $rows['P-2']['total_co2e_kg'], 0.001, 'Entri terhapus tidak dihitung');
        $this->assertSame(1, $rows['P-2']['entry_count']);
        $this->assertSame(0.0, $rows['P-1']['total_co2e_kg'], 'Project lain bernilai 0 saat difilter');
        $this->assertSame(0, $rows['P-1']['entry_count']);
    }

    public function test_admin_dashboard_excludes_entries_of_deleted_projects(): void
    {
        $this->projectWithEmission('P-1', 100);
        $deleted = $this->projectWithEmission('P-2', 50);
        $deleted->delete();

        $as = fn (string $url) => $this->actingAs($this->admin, 'sanctum')->getJson($url)->assertOk();

        $this->assertEqualsWithDelta(231.0, $as('/api/v1/dashboard/summary?period_year=2026')->json('data.total_co2e_kg'), 0.001);
        $this->assertSame(1, $as('/api/v1/dashboard/summary?period_year=2026')->json('data.entry_count'));
        $this->assertEqualsWithDelta(231.0, collect($as('/api/v1/dashboard/trend?period_year=2026')->json('data'))->sum('total_co2e_kg'), 0.001);
        $this->assertEqualsWithDelta(231.0, collect($as('/api/v1/dashboard/category-breakdown?period_year=2026')->json('data'))->sum('total_co2e_kg'), 0.001);
        $this->assertCount(1, $as('/api/v1/dashboard/top-entries?period_year=2026')->json('data'));
    }
}
