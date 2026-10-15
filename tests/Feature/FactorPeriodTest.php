<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CarbonEntry;
use App\Models\EmissionFactor;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsCarbonData;
use Tests\TestCase;

/**
 * Nilai faktor emisi berlaku per periode: entri memakai nilai yang berlaku pada tanggal aktivitasnya.
 * Nilai baru/diubah hanya menghitung ulang entri draft & ditolak; yang submitted/approved tetap.
 */
class FactorPeriodTest extends TestCase
{
    use BuildsCarbonData, RefreshDatabase;

    private const MIGRATION = '2026_10_15_000001_create_emission_factor_values_table.php';

    private User $owner;

    private Project $project;

    private EmissionFactor $pln;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMasterData();
        $this->owner = User::factory()->create(['role' => 'pm']);
        $this->project = $this->makeProject($this->owner);
        $this->pln = EmissionFactor::where('slug', 'electricity_jawa_bali')->firstOrFail(); // 0,87
    }

    private function url(string $suffix = ''): string
    {
        return "/api/v1/emission-factors/{$this->pln->id}/values{$suffix}";
    }

    private function addPeriod(string $validFrom, float $value): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin, 'sanctum')->postJson($this->url(), ['valid_from' => $validFrom, 'factor_value' => $value, 'source' => 'ESDM 2025']);
    }

    private function entry(string $date, string $status, float $quantity = 100): CarbonEntry
    {
        $entry = $this->makeApprovedEntry($this->project, $this->owner, $quantity, $date, 'electricity_jawa_bali');
        $entry->update(['status' => $status]);

        return $entry->fresh();
    }

    public function test_existing_factors_start_with_one_open_ended_period(): void
    {
        $this->actingAs($this->owner, 'sanctum')->getJson($this->url())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.valid_from', '2000-01-01')
            ->assertJsonPath('data.0.factor_value', 0.87)
            ->assertJsonPath('data.0.valid_until', null);
    }

    public function test_entries_use_the_value_valid_on_their_activity_date(): void
    {
        $this->addPeriod('2025-01-01', 0.8)->assertCreated();
        $as = $this->actingAs($this->owner, 'sanctum');
        $store = fn (string $date) => $as->postJson("/api/v1/projects/{$this->project->id}/entries", [
            'emission_factor_id' => $this->pln->id, 'entry_date' => $date, 'quantity' => 100,
        ])->assertCreated();

        $store('2024-12-31')->assertJsonPath('data.emission_factor_value', 0.87)->assertJsonPath('data.co2e_kg', 87);
        $store('2025-02-01')->assertJsonPath('data.emission_factor_value', 0.8)->assertJsonPath('data.co2e_kg', 80);
    }

    public function test_new_period_recalculates_only_draft_and_rejected_entries_in_range(): void
    {
        $draft = $this->entry('2025-03-01', 'draft');
        $rejected = $this->entry('2025-04-01', 'rejected');
        $approved = $this->entry('2025-05-01', 'approved');
        $before = $this->entry('2024-06-01', 'draft');

        $this->addPeriod('2025-01-01', 0.8)
            ->assertCreated()
            ->assertJsonPath('meta.recalculated', 2)
            ->assertJsonPath('meta.locked', 1);

        $this->assertSame('80.0000', $draft->fresh()->co2e_kg);
        $this->assertSame('0.80000000', $rejected->fresh()->emission_factor_value);
        $this->assertSame('0.87000000', $approved->fresh()->emission_factor_value);
        $this->assertSame('0.87000000', $before->fresh()->emission_factor_value);

        // Perhitungan ulang tercatat di riwayat entri dengan admin sebagai pelaku
        $this->assertTrue(AuditLog::where('model_type', CarbonEntry::class)->where('model_id', $draft->id)
            ->where('action', 'updated')->where('user_id', $this->admin->id)->exists());
    }

    public function test_factor_current_value_follows_the_period_valid_today(): void
    {
        $this->addPeriod(now()->subMonth()->toDateString(), 0.75)->assertCreated();
        $this->assertSame('0.75000000', $this->pln->fresh()->factor_value);

        $this->addPeriod(now()->addMonth()->toDateString(), 0.7)->assertCreated();
        $this->assertSame('0.75000000', $this->pln->fresh()->factor_value);
    }

    public function test_editing_and_deleting_a_period_recalculates_drafts(): void
    {
        $draft = $this->entry('2025-03-01', 'draft');
        $periodId = $this->addPeriod('2025-01-01', 0.8)->json('data.id');

        $this->actingAs($this->admin, 'sanctum')->putJson($this->url("/{$periodId}"), ['factor_value' => 0.82])
            ->assertOk()->assertJsonPath('meta.recalculated', 1);
        $this->assertSame('82.0000', $draft->fresh()->co2e_kg);

        $this->actingAs($this->admin, 'sanctum')->deleteJson($this->url("/{$periodId}"))
            ->assertOk()->assertJsonPath('meta.recalculated', 1);
        $this->assertSame('87.0000', $draft->fresh()->co2e_kg);
    }

    public function test_period_rules_are_validated(): void
    {
        $this->addPeriod('2025-01-01', 0.8)->assertCreated();
        $this->addPeriod('2025-01-01', 0.9)->assertUnprocessable()->assertJsonValidationErrors('valid_from');
        $this->addPeriod('2025-06-01', -1)->assertUnprocessable()->assertJsonValidationErrors('factor_value');

        $onlyPeriod = EmissionFactor::where('slug', 'gasoline_vehicle')->firstOrFail();
        $periodId = $this->actingAs($this->admin, 'sanctum')->getJson("/api/v1/emission-factors/{$onlyPeriod->id}/values")->json('data.0.id');
        $this->actingAs($this->admin, 'sanctum')->deleteJson("/api/v1/emission-factors/{$onlyPeriod->id}/values/{$periodId}")
            ->assertUnprocessable();
    }

    public function test_only_admins_manage_periods(): void
    {
        $this->actingAs($this->owner, 'sanctum')->postJson($this->url(), ['valid_from' => '2025-01-01', 'factor_value' => 0.8])->assertForbidden();
    }

    public function test_editing_factor_value_on_the_factor_corrects_the_current_period(): void
    {
        // Klien lama (form faktor tanpa periode) tetap bisa mengoreksi nilai yang berlaku sekarang
        $draft = $this->entry('2025-03-01', 'draft');

        $this->actingAs($this->admin, 'sanctum')->putJson("/api/v1/emission-factors/{$this->pln->id}", [
            'category_id' => $this->pln->category_id, 'name' => $this->pln->name, 'slug' => $this->pln->slug,
            'source_unit' => $this->pln->source_unit, 'factor_value' => 0.9,
        ])->assertOk();

        $this->assertSame(['0.90000000'], $this->pln->values()->pluck('factor_value')->all());
        $this->assertSame('90.0000', $draft->fresh()->co2e_kg);
    }

    public function test_imported_rows_use_the_value_of_each_row_date(): void
    {
        $this->addPeriod('2025-01-01', 0.8)->assertCreated();

        $this->actingAs($this->owner, 'sanctum')->postJson("/api/v1/projects/{$this->project->id}/entries/import", [
            'rows' => [
                ['entry_date' => '2024-12-01', 'emission_factor_id' => $this->pln->id, 'quantity' => 100],
                ['entry_date' => '2025-12-01', 'emission_factor_id' => $this->pln->id, 'quantity' => 100],
            ],
        ])->assertSuccessful();

        $this->assertSame(['87.0000', '80.0000'], CarbonEntry::where('project_id', $this->project->id)->orderBy('entry_date')->pluck('co2e_kg')->all());
    }

    public function test_migration_moves_current_values_into_periods(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Uji DDL hanya dijalankan di SQLite.');
        }

        $migration = require database_path('migrations/'.self::MIGRATION);
        $migration->down();
        DB::table('emission_factors')->where('slug', 'gasoline_vehicle')->update(['factor_value' => 2.5]);
        $migration->up();

        $this->assertSame(EmissionFactor::count(), DB::table('emission_factor_values')->count());
        $gasoline = EmissionFactor::where('slug', 'gasoline_vehicle')->firstOrFail();
        $this->assertEquals(2.5, DB::table('emission_factor_values')->where('emission_factor_id', $gasoline->id)->value('factor_value'));
    }
}
