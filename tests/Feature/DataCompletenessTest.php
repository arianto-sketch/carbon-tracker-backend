<?php

namespace Tests\Feature;

use App\Models\EmissionFactor;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsCarbonData;
use Tests\TestCase;

/**
 * Kelengkapan data per bulan: faktor "rutin" (tercatat di >= 2 dari 3 bulan sebelumnya) yang kosong
 * dianggap belum diisi; owner/member bisa menandainya "tidak ada data bulan ini".
 */
class DataCompletenessTest extends TestCase
{
    use BuildsCarbonData, RefreshDatabase;

    private User $owner;

    private User $member;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMasterData();
        $this->owner = User::factory()->create(['role' => 'pm']);
        $this->member = User::factory()->create(['role' => 'pm']);
        $this->project = $this->makeProject($this->owner);
        $this->addMember($this->project, $this->member);
        // Hari ini 5 November 2026 (WIB): bulan terakhir yang lengkap = Oktober 2026
        $this->travelTo(Carbon::parse('2026-11-05 08:00', 'Asia/Jakarta'));

        // Listrik: Agustus & September, Oktober kosong -> rutin, belum diisi
        $this->makeApprovedEntry($this->project, $this->member, 500, '2026-08-20', 'electricity_jawa_bali');
        $this->makeApprovedEntry($this->project, $this->member, 520, '2026-09-20', 'electricity_jawa_bali');
        // Bensin: tiap bulan termasuk Oktober
        foreach (['2026-07-10', '2026-08-10', '2026-09-10', '2026-10-10'] as $date) {
            $this->makeApprovedEntry($this->project, $this->member, 40, $date);
        }
        // Penerbangan sekali di September: bukan faktor rutin
        $this->makeApprovedEntry($this->project, $this->member, 2, '2026-09-03', 'flight_domestic');
        // Air galon: Agustus, September, dan Oktober hanya draft
        $this->makeApprovedEntry($this->project, $this->member, 10, '2026-08-05', 'water_gallon');
        $this->makeApprovedEntry($this->project, $this->member, 10, '2026-09-05', 'water_gallon');
        $this->makeApprovedEntry($this->project, $this->member, 10, '2026-10-05', 'water_gallon')
            ->update(['status' => 'draft', 'approved_by' => null, 'approved_at' => null]);
    }

    private function url(string $suffix = ''): string
    {
        return "/api/v1/projects/{$this->project->id}{$suffix}";
    }

    private function statuses(string $factorName): array
    {
        $factors = $this->actingAs($this->owner, 'sanctum')->getJson($this->url('/data-completeness'))
            ->assertOk()
            ->json('data.factors');

        return collect($factors)->firstWhere('name', $factorName)['statuses'] ?? [];
    }

    public function test_grid_shows_recurring_factors_for_the_last_three_complete_months(): void
    {
        $this->actingAs($this->member, 'sanctum')->getJson($this->url('/data-completeness'))
            ->assertOk()
            ->assertJsonPath('data.months.0.label', 'Agustus 2026')
            ->assertJsonPath('data.months.2.label', 'Oktober 2026')
            ->assertJsonPath('data.months.2.missing_count', 1);

        $this->assertSame(['recorded', 'recorded', 'missing'], $this->statuses('Listrik PLN Jawa-Bali'));
        $this->assertSame(['recorded', 'recorded', 'recorded'], $this->statuses('Bensin (kendaraan)'));
        $this->assertSame(['none', 'recorded', 'none'], $this->statuses('Penerbangan domestik'));
        $this->assertSame(['recorded', 'recorded', 'draft'], $this->statuses('Air minum galon'));
    }

    public function test_writers_mark_a_gap_as_no_data_and_can_undo_it(): void
    {
        $electricity = EmissionFactor::where('slug', 'electricity_jawa_bali')->value('id');
        $payload = ['period_year' => 2026, 'period_month' => 10, 'emission_factor_id' => $electricity];

        $this->actingAs($this->member, 'sanctum')->postJson($this->url('/data-gaps/dismissals'), $payload)->assertCreated();
        // Diulang: tetap satu penandaan
        $this->actingAs($this->member, 'sanctum')->postJson($this->url('/data-gaps/dismissals'), $payload)->assertCreated();
        $this->assertDatabaseCount('data_gap_dismissals', 1);
        $this->assertSame(['recorded', 'recorded', 'dismissed'], $this->statuses('Listrik PLN Jawa-Bali'));

        $this->actingAs($this->owner, 'sanctum')->deleteJson($this->url('/data-gaps/dismissals?'.http_build_query($payload)))->assertOk();
        $this->assertSame(['recorded', 'recorded', 'missing'], $this->statuses('Listrik PLN Jawa-Bali'));
    }

    public function test_viewers_outsiders_and_future_months_cannot_be_marked(): void
    {
        $viewer = User::factory()->create(['role' => 'pm']);
        $this->addMember($this->project, $viewer, 'viewer');
        $payload = ['period_year' => 2026, 'period_month' => 10];

        $this->actingAs($viewer, 'sanctum')->postJson($this->url('/data-gaps/dismissals'), $payload)->assertForbidden();
        $this->actingAs(User::factory()->create(), 'sanctum')->postJson($this->url('/data-gaps/dismissals'), $payload)->assertForbidden();
        $this->actingAs(User::factory()->create(), 'sanctum')->getJson($this->url('/data-completeness'))->assertForbidden();
        $this->actingAs($this->owner, 'sanctum')->postJson($this->url('/data-gaps/dismissals'), ['period_year' => 2026, 'period_month' => 12])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('period_month');
    }

    public function test_dashboard_lists_incomplete_projects_of_the_last_month(): void
    {
        $emptyProject = $this->makeProject($this->owner, 'PRJ-2');
        $archived = $this->makeProject($this->owner, 'PRJ-3');
        $archived->update(['status' => 'archived']);

        $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/dashboard/data-completeness')
            ->assertOk()
            ->assertJsonPath('data.period_label', 'Oktober 2026')
            ->assertJsonPath('data.active_count', 2)
            ->assertJsonCount(2, 'data.incomplete')
            ->assertJsonPath('data.incomplete.0.name', $this->project->name)
            ->assertJsonPath('data.incomplete.0.empty', false)
            ->assertJsonPath('data.incomplete.0.missing_factors', ['Listrik PLN Jawa-Bali'])
            ->assertJsonPath('data.incomplete.1.id', $emptyProject->id)
            ->assertJsonPath('data.incomplete.1.empty', true);

        // Tanda "tidak ada data" untuk seluruh project kosong
        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/projects/{$emptyProject->id}/data-gaps/dismissals", ['period_year' => 2026, 'period_month' => 10])
            ->assertCreated();
        $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/dashboard/data-completeness')
            ->assertJsonCount(1, 'data.incomplete');

        $this->actingAs(User::factory()->create(), 'sanctum')->getJson('/api/v1/dashboard/data-completeness')
            ->assertJsonCount(0, 'data.incomplete');
    }

    public function test_deactivated_factors_are_never_reported_missing(): void
    {
        EmissionFactor::where('slug', 'electricity_jawa_bali')->update(['is_active' => false]);

        $this->assertSame(['recorded', 'recorded', 'none'], $this->statuses('Listrik PLN Jawa-Bali'));
        $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/dashboard/data-completeness')
            ->assertJsonCount(0, 'data.incomplete');
    }

    public function test_marking_every_recurring_factor_means_the_month_is_not_empty(): void
    {
        $other = $this->makeProject($this->owner, 'PRJ-2');
        $this->makeApprovedEntry($other, $this->owner, 100, '2026-08-20', 'electricity_jawa_bali');
        $this->makeApprovedEntry($other, $this->owner, 100, '2026-09-20', 'electricity_jawa_bali');
        $electricity = EmissionFactor::where('slug', 'electricity_jawa_bali')->value('id');

        $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/dashboard/data-completeness')
            ->assertJsonPath('data.incomplete.1.empty', true)
            ->assertJsonPath('data.incomplete.1.missing_factors', ['Listrik PLN Jawa-Bali']);

        $this->actingAs($this->owner, 'sanctum')->postJson("/api/v1/projects/{$other->id}/data-gaps/dismissals",
            ['period_year' => 2026, 'period_month' => 10, 'emission_factor_id' => $electricity])->assertCreated();

        $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/dashboard/data-completeness')
            ->assertJsonCount(1, 'data.incomplete')
            ->assertJsonPath('data.incomplete.0.id', $this->project->id);
    }

    public function test_months_follow_business_time_across_a_year_boundary(): void
    {
        // 31 Jan 2027 18.00 UTC = 1 Feb 2027 01.00 WIB: bulan terakhir yang lengkap = Januari 2027
        $this->travelTo(Carbon::parse('2027-01-31 18:00', 'UTC'));
        $this->makeApprovedEntry($this->project, $this->member, 500, '2026-11-20', 'electricity_jawa_bali');
        $this->makeApprovedEntry($this->project, $this->member, 500, '2026-12-20', 'electricity_jawa_bali');

        $this->actingAs($this->owner, 'sanctum')->getJson($this->url('/data-completeness'))
            ->assertJsonPath('data.months.0.label', 'November 2026')
            ->assertJsonPath('data.months.2.label', 'Januari 2027');
        $this->assertSame(['recorded', 'recorded', 'missing'], $this->statuses('Listrik PLN Jawa-Bali'));
    }

    public function test_projects_outside_their_date_range_are_not_expected_to_have_data(): void
    {
        $this->project->update(['end_date' => '2026-09-30']);

        $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/dashboard/data-completeness')
            ->assertJsonPath('data.active_count', 0)
            ->assertJsonCount(0, 'data.incomplete');
    }
}
