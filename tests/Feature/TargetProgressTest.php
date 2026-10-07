<?php

namespace Tests\Feature;

use App\Models\CarbonEntry;
use App\Models\CarbonTarget;
use App\Models\EmissionFactor;
use App\Models\Project;
use App\Models\User;
use App\Services\CarbonTargetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsCarbonData;
use Tests\TestCase;

class TargetProgressTest extends TestCase
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
    }

    /** Entri dengan nilai emisi langsung, supaya angka yang diharapkan mudah dihitung. */
    private function entry(string $factorSlug, float $co2e, string $date, string $status = 'approved', ?Project $project = null): void
    {
        $factor = EmissionFactor::where('slug', $factorSlug)->firstOrFail();

        CarbonEntry::create([
            'project_id'            => ($project ?? $this->project)->id,
            'emission_factor_id'    => $factor->id,
            'category_id'           => $factor->category_id,
            'entry_date'            => $date,
            'period_month'          => (int) substr($date, 5, 2),
            'period_year'           => (int) substr($date, 0, 4),
            'quantity'              => 1,
            'source_unit'           => $factor->source_unit,
            'emission_factor_value' => $co2e,
            'co2e_kg'               => $co2e,
            'status'                => $status,
            'created_by'            => $this->owner->id,
        ]);
    }

    private function target(string $type, int $year, ?int $value = null, ?string $factorSlug = null): CarbonTarget
    {
        return CarbonTarget::create([
            'project_id'     => $this->project->id,
            'category_id'    => $factorSlug ? EmissionFactor::where('slug', $factorSlug)->value('category_id') : null,
            'period_type'    => $type,
            'period_year'    => $year,
            'period_value'   => $value,
            'target_co2e_kg' => 1000,
            'created_by'     => $this->owner->id,
        ]);
    }

    private function actuals(): array
    {
        return app(CarbonTargetService::class)->getProgress($this->project)
            ->mapWithKeys(fn ($row) => [$row['target_id'] => $row['actual_co2e_kg']])
            ->all();
    }

    public function test_actual_emission_per_target_type_category_and_period(): void
    {
        $this->entry('gasoline_vehicle', 100, '2026-01-10');
        $this->entry('gasoline_vehicle', 40, '2026-03-20');
        $this->entry('gasoline_vehicle', 7, '2026-04-05');
        $this->entry('paper_a4_ream', 25, '2026-02-14');
        $this->entry('paper_a4_ream', 3, '2026-03-01', 'draft');            // belum approved
        $this->entry('gasoline_vehicle', 1000, '2025-03-20');               // tahun lain
        $this->entry('gasoline_vehicle', 500, '2026-03-20', 'approved', $this->makeProject($this->owner, 'LAIN'));

        $expected = [
            $this->target('yearly', 2026)->id                                => 172.0,
            $this->target('yearly', 2026, null, 'gasoline_vehicle')->id      => 147.0,
            $this->target('quarterly', 2026, 1)->id                          => 165.0,
            $this->target('quarterly', 2026, 2, 'gasoline_vehicle')->id      => 7.0,
            $this->target('monthly', 2026, 3, 'gasoline_vehicle')->id        => 40.0,
            $this->target('monthly', 2026, 2, 'paper_a4_ream')->id           => 25.0,
            $this->target('yearly', 2025)->id                                => 1000.0,
        ];

        $actuals = $this->actuals();
        foreach ($expected as $id => $value) {
            $this->assertEqualsWithDelta($value, $actuals[$id], 0.0001, "Target {$id}");
        }
    }

    public function test_progress_uses_a_constant_number_of_queries(): void
    {
        $this->entry('gasoline_vehicle', 100, '2026-01-10');
        // Berkategori, supaya eager-load kategori sudah ikut terhitung di pengukuran pertama
        $this->target('yearly', 2026, null, 'gasoline_vehicle');

        $count = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actuals();
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $one = $count();
        foreach (range(1, 6) as $month) {
            $this->target('monthly', 2026, $month, 'gasoline_vehicle');
        }

        $this->assertSame($one, $count());
    }
}
