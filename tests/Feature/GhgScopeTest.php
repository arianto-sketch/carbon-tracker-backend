<?php

namespace Tests\Feature;

use App\Exports\CarbonReportExport;
use App\Models\CarbonEntry;
use App\Models\EmissionFactor;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsCarbonData;
use Tests\TestCase;

/**
 * Scope GHG Protocol ditentukan per faktor emisi dan disalin ke entri saat dicatat (snapshot),
 * sama seperti nilai faktornya.
 */
class GhgScopeTest extends TestCase
{
    use BuildsCarbonData, RefreshDatabase;

    private const MIGRATION = '2026_10_14_000000_add_ghg_scope_to_factors_and_entries.php';

    private User $owner;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMasterData();
        $this->owner = User::factory()->create();
        $this->project = $this->makeProject($this->owner);
    }

    private function factor(string $slug): EmissionFactor
    {
        return EmissionFactor::where('slug', $slug)->firstOrFail();
    }

    private function factorPayload(EmissionFactor $factor, array $overrides = []): array
    {
        return array_merge([
            'category_id'  => $factor->category_id,
            'name'         => $factor->name,
            'slug'         => $factor->slug,
            'source_unit'  => $factor->source_unit,
            'factor_value' => (float) $factor->factor_value,
        ], $overrides);
    }

    public function test_seeded_factors_get_default_ghg_scopes(): void
    {
        $this->assertSame([
            'diesel_vehicle'        => 1,
            'electricity_jawa_bali' => 2,
            'electricity_sumatra'   => 2,
            'flight_domestic'       => 3,
            'flight_international'  => 3,
            'gasoline_vehicle'      => 1,
            'paper_a4_ream'         => 3,
            'water_gallon'          => 3,
        ], EmissionFactor::orderBy('slug')->pluck('scope', 'slug')->all());
    }

    public function test_entry_keeps_the_scope_its_factor_had_when_recorded(): void
    {
        $electricity = $this->factor('electricity_jawa_bali');

        $response = $this->actingAs($this->owner, 'sanctum')->postJson("/api/v1/projects/{$this->project->id}/entries", [
            'emission_factor_id' => $electricity->id,
            'entry_date'         => '2026-03-10',
            'quantity'           => 100,
        ])->assertCreated()->assertJsonPath('data.scope', 2);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/emission-factors/{$electricity->id}", $this->factorPayload($electricity, ['scope' => 3]))
            ->assertOk()
            ->assertJsonPath('data.scope', 3);

        $this->assertSame(2, CarbonEntry::findOrFail($response->json('data.id'))->scope);
    }

    public function test_factor_scope_is_validated_and_defaults_to_scope_3(): void
    {
        $payload = ['category_id' => $this->gasolineFactor()->category_id, 'name' => 'Gas LPG', 'slug' => 'lpg', 'source_unit' => 'kg', 'factor_value' => 2.98];

        $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/emission-factors', [...$payload, 'scope' => 4])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('scope');

        $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/emission-factors', [...$payload, 'scope' => 1])
            ->assertCreated()
            ->assertJsonPath('data.scope', 1);

        // Klien lama yang belum mengirim scope: Scope 3 (emisi tidak langsung lainnya)
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/emission-factors', [...$payload, 'slug' => 'lpg-2'])
            ->assertCreated()
            ->assertJsonPath('data.scope', 3);
    }

    public function test_updating_a_factor_without_scope_keeps_its_scope(): void
    {
        $gasoline = $this->gasolineFactor();

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/emission-factors/{$gasoline->id}", $this->factorPayload($gasoline, ['factor_value' => 2.4]))
            ->assertOk()
            ->assertJsonPath('data.scope', 1);
    }

    public function test_dashboard_scope_breakdown_covers_all_scopes_for_approved_entries(): void
    {
        $this->makeApprovedEntry($this->project, $this->owner, 100);                                    // bensin: 231 kg, Scope 1
        $this->makeApprovedEntry($this->project, $this->owner, 300, '2026-03-15', 'electricity_jawa_bali'); // 261 kg, Scope 2
        $this->makeEntry($this->project, $this->owner, 'draft');                                          // tidak dihitung

        $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/dashboard/scope-breakdown?period_year=2026')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.scope', 1)
            ->assertJsonPath('data.0.total_co2e_kg', 231.0)
            ->assertJsonPath('data.0.entry_count', 1)
            ->assertJsonPath('data.0.percentage', 46.95)
            ->assertJsonPath('data.1.scope', 2)
            ->assertJsonPath('data.1.total_co2e_kg', 261.0)
            ->assertJsonPath('data.1.percentage', 53.05)
            ->assertJsonPath('data.2.scope', 3)
            ->assertJsonPath('data.2.total_co2e_kg', 0.0)
            ->assertJsonPath('data.2.percentage', 0.0);
    }

    public function test_scope_breakdown_only_counts_projects_the_user_can_see(): void
    {
        $this->makeApprovedEntry($this->project, $this->owner, 100);

        $this->actingAs(User::factory()->create(), 'sanctum')->getJson('/api/v1/dashboard/scope-breakdown')
            ->assertOk()
            ->assertJsonPath('data.0.total_co2e_kg', 0.0);
    }

    public function test_export_lists_the_scope_of_each_entry_in_the_last_column(): void
    {
        $this->makeApprovedEntry($this->project, $this->owner, 100, '2026-03-15', 'electricity_jawa_bali');
        $export = new CarbonReportExport(['period_year' => 2026]);

        $this->assertSame('Scope GHG', last($export->headings()));
        $this->assertSame('Scope 2', last($export->map($export->query()->firstOrFail())));
    }

    public function test_migration_backfills_scopes_for_existing_factors_and_entries(): void
    {
        // DDL di MySQL meng-commit transaksi RefreshDatabase; skenario ini diuji manual di MySQL
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Uji DDL hanya dijalankan di SQLite.');
        }

        $entry = $this->makeApprovedEntry($this->project, $this->owner, 100, '2026-03-15', 'electricity_jawa_bali');
        $migration = require database_path('migrations/'.self::MIGRATION);
        $migration->down();

        // Faktor buatan admin sebelum ada kolom scope
        DB::table('emission_factors')->insert([
            'category_id' => $this->gasolineFactor()->category_id, 'name' => 'Faktor Kustom', 'slug' => 'custom_factor',
            'source_unit' => 'kg', 'factor_value' => 1, 'factor_unit' => 'kg CO2e', 'is_active' => true, 'created_by' => $this->admin->id,
        ]);

        $migration->up();

        $scopes = DB::table('emission_factors')->pluck('scope', 'slug');
        $this->assertEquals(2, $scopes['electricity_jawa_bali']);
        $this->assertEquals(1, $scopes['diesel_vehicle']);
        $this->assertEquals(3, $scopes['flight_domestic']);
        $this->assertEquals(3, $scopes['custom_factor']);
        $this->assertEquals(2, DB::table('carbon_entries')->where('id', $entry->id)->value('scope'));
    }
}
