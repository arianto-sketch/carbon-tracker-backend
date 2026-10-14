<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsCarbonData;
use Tests\TestCase;

/**
 * Proyeksi emisi setahun (run-rate linear: total s.d. bulan berjalan ÷ jumlah bulan × 12)
 * dan perbandingan dengan periode yang sama tahun sebelumnya.
 */
class EmissionOutlookTest extends TestCase
{
    use BuildsCarbonData, RefreshDatabase;

    private User $owner;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMasterData();
        $this->owner = User::factory()->create();
        $this->project = $this->makeProject($this->owner);
        // Bulan berjalan: April 2026 (zona waktu bisnis)
        $this->travelTo(Carbon::parse('2026-04-10 10:00', 'Asia/Jakarta'));
    }

    /** Bensin 2,31 kg CO2e/liter: 100 liter = 231 kg */
    private function litersOn(Project $project, array $litersByDate): void
    {
        foreach ($litersByDate as $date => $liters) {
            $this->makeApprovedEntry($project, $this->owner, $liters, $date);
        }
    }

    public function test_projection_uses_run_rate_and_compares_with_same_period_last_year(): void
    {
        $this->litersOn($this->project, ['2026-01-10' => 100, '2026-03-05' => 200, '2026-04-02' => 100]); // 924 kg
        // 2025: Februari masuk periode pembanding (Jan–Apr), Juni hanya dihitung di total setahun
        $this->litersOn($this->project, ['2025-02-10' => 100, '2025-06-10' => 400]);
        $this->makeEntry($this->project, $this->owner, 'draft');

        $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/dashboard/outlook')
            ->assertOk()
            ->assertJsonPath('data.year', 2026)
            ->assertJsonPath('data.months_elapsed', 4)
            ->assertJsonPath('data.ytd_co2e_kg', 924.0)
            ->assertJsonPath('data.projected_annual_co2e_kg', 2772.0)
            ->assertJsonPath('data.previous_year.year', 2025)
            ->assertJsonPath('data.previous_year.same_period_co2e_kg', 231.0)
            ->assertJsonPath('data.previous_year.full_year_co2e_kg', 1155.0)
            ->assertJsonPath('data.yoy_change_pct', 300.0);
    }

    public function test_past_year_projection_equals_its_actual_total(): void
    {
        $this->litersOn($this->project, ['2025-02-10' => 100, '2025-06-10' => 400]);

        $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/dashboard/outlook?period_year=2025')
            ->assertOk()
            ->assertJsonPath('data.months_elapsed', 12)
            ->assertJsonPath('data.ytd_co2e_kg', 1155.0)
            ->assertJsonPath('data.projected_annual_co2e_kg', 1155.0)
            ->assertJsonPath('data.previous_year.same_period_co2e_kg', 0.0)
            // Tahun sebelumnya nol: persentase perubahan tidak terdefinisi
            ->assertJsonPath('data.yoy_change_pct', null);
    }

    public function test_future_year_has_no_projection(): void
    {
        $this->litersOn($this->project, ['2026-01-10' => 100]);

        $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/dashboard/outlook?period_year=2027')
            ->assertOk()
            ->assertJsonPath('data.months_elapsed', 0)
            ->assertJsonPath('data.ytd_co2e_kg', 0.0)
            ->assertJsonPath('data.projected_annual_co2e_kg', null)
            ->assertJsonPath('data.yoy_change_pct', null);
    }

    public function test_outlook_respects_project_filter_and_project_access(): void
    {
        $this->litersOn($this->project, ['2026-02-10' => 100]);
        $other = $this->makeProject(User::factory()->create(), 'PRJ-2');
        $this->litersOn($other, ['2026-02-10' => 300]);

        $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/dashboard/outlook')
            ->assertJsonPath('data.ytd_co2e_kg', 231.0);

        $this->actingAs($this->admin, 'sanctum')->getJson("/api/v1/dashboard/outlook?project_id={$other->id}")
            ->assertJsonPath('data.ytd_co2e_kg', 693.0);

        $this->actingAs(User::factory()->create(), 'sanctum')->getJson('/api/v1/dashboard/outlook')
            ->assertJsonPath('data.ytd_co2e_kg', 0.0);
    }

    public function test_outlook_validates_the_year(): void
    {
        $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/dashboard/outlook?period_year=abc')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('period_year');
    }
}
