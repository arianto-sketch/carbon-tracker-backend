<?php

namespace Tests\Feature;

use App\Models\ReportJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCarbonData;
use Tests\TestCase;

class ListPaginationTest extends TestCase
{
    use BuildsCarbonData, RefreshDatabase;

    private User $pm;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMasterData();
        $this->pm = User::factory()->create();
    }

    public function test_projects_respect_per_page_and_search(): void
    {
        foreach (range(1, 20) as $i) {
            $this->makeProject($this->pm, sprintf('PRJ-%02d', $i));
        }
        $this->makeProject($this->pm, 'ALPHA-X');

        $this->actingAs($this->pm, 'sanctum')->getJson('/api/v1/projects')
            ->assertOk()->assertJsonPath('meta.per_page', 15)->assertJsonPath('meta.total', 21);

        $this->actingAs($this->pm, 'sanctum')->getJson('/api/v1/projects?per_page=5&page=2')
            ->assertOk()->assertJsonCount(5, 'data')->assertJsonPath('meta.current_page', 2)->assertJsonPath('meta.last_page', 5);

        $this->actingAs($this->pm, 'sanctum')->getJson('/api/v1/projects?search=alpha')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'ALPHA-X');
    }

    public function test_entries_respect_per_page(): void
    {
        $project = $this->makeProject($this->pm);
        foreach (range(1, 7) as $_) {
            $this->makeEntry($project, $this->pm);
        }

        $this->actingAs($this->pm, 'sanctum')->getJson("/api/v1/projects/{$project->id}/entries?per_page=3&page=3")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.last_page', 3)->assertJsonPath('meta.total', 7);
    }

    public function test_emission_factors_can_be_loaded_in_one_page(): void
    {
        $total = $this->actingAs($this->pm, 'sanctum')->getJson('/api/v1/emission-factors?per_page=100')
            ->assertOk()->assertJsonPath('meta.per_page', 100)->json('meta.total');

        $this->assertGreaterThan(0, $total);
        $this->actingAs($this->pm, 'sanctum')->getJson('/api/v1/emission-factors?per_page=100')->assertJsonCount($total, 'data');
    }

    public function test_report_history_respects_per_page(): void
    {
        foreach (range(1, 4) as $_) {
            ReportJob::create(['user_id' => $this->pm->id, 'filters' => [], 'format' => 'xlsx', 'status' => 'done']);
        }

        $this->actingAs($this->pm, 'sanctum')->getJson('/api/v1/reports/history?per_page=2')
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.last_page', 2);
    }

    public function test_per_page_is_validated(): void
    {
        $project = $this->makeProject($this->pm);

        foreach ([
            '/api/v1/projects?per_page=1000',
            "/api/v1/projects/{$project->id}/entries?per_page=0",
            '/api/v1/emission-factors?per_page=abc',
            '/api/v1/reports/history?per_page=101',
        ] as $url) {
            $this->actingAs($this->pm, 'sanctum')->getJson($url)->assertStatus(422)->assertJsonValidationErrors('per_page');
        }
    }
}
