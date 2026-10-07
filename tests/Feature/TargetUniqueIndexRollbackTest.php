<?php

namespace Tests\Feature;

use App\Models\CarbonTarget;
use App\Models\EmissionCategory;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\Concerns\BuildsCarbonData;
use Tests\TestCase;

/**
 * Rollback migration yang menghapus unique index target harus bisa memasang index itu lagi
 * walau sudah ada target soft-deleted yang dibuat ulang untuk periode yang sama.
 */
class TargetUniqueIndexRollbackTest extends TestCase
{
    use BuildsCarbonData, RefreshDatabase;

    private const MIGRATION = '2026_10_06_000000_drop_unique_index_on_carbon_targets.php';

    private Project $project;

    private int $categoryId;

    protected function setUp(): void
    {
        parent::setUp();

        // DDL di MySQL meng-commit transaksi RefreshDatabase; skenario ini diuji manual di MySQL
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Uji DDL hanya dijalankan di SQLite.');
        }

        $this->seedMasterData();
        $this->project = $this->makeProject(User::factory()->create());
        $this->categoryId = EmissionCategory::value('id');
    }

    private function migration(): object
    {
        return require database_path('migrations/'.self::MIGRATION);
    }

    private function target(int $month, bool $deleted = false): CarbonTarget
    {
        $target = CarbonTarget::create([
            'project_id'     => $this->project->id,
            'category_id'    => $this->categoryId,
            'period_type'    => 'monthly',
            'period_year'    => 2026,
            'period_value'   => $month,
            'target_co2e_kg' => 100,
            'created_by'     => $this->project->created_by,
        ]);

        if ($deleted) {
            $target->delete();
        }

        return $target;
    }

    private function uniqueIndexExists(): bool
    {
        return collect(Schema::getIndexes('carbon_targets'))->contains(fn ($index) => $index['name'] === 'carbon_targets_unique');
    }

    public function test_rollback_drops_conflicting_soft_deleted_targets_and_restores_the_index(): void
    {
        // Maret: target dihapus lalu dibuat ulang. April: dihapus dua kali, tidak ada yang aktif.
        $marchOld = $this->target(3, deleted: true);
        $marchActive = $this->target(3);
        $aprilOld = $this->target(4, deleted: true);
        $aprilNewer = $this->target(4, deleted: true);
        $may = $this->target(5, deleted: true);

        $this->migration()->down();

        $this->assertTrue($this->uniqueIndexExists());
        $remaining = CarbonTarget::withTrashed()->pluck('id')->all();
        $this->assertEqualsCanonicalizing([$marchActive->id, $aprilNewer->id, $may->id], $remaining);
        $this->assertNotContains($marchOld->id, $remaining);
        $this->assertNotContains($aprilOld->id, $remaining);

        $this->migration()->up();
    }

    public function test_rollback_stops_without_changes_when_active_targets_conflict(): void
    {
        $first = $this->target(3);
        $second = $this->target(3); // hanya mungkin lewat data lama/manual; service menolak duplikat
        $deleted = $this->target(3, deleted: true);

        try {
            $this->migration()->down();
            $this->fail('Rollback seharusnya dibatalkan.');
        } catch (RuntimeException $e) {
            // Pesan jelas dari migration, bukan error SQL dari pemasangan index
            $this->assertNotInstanceOf(QueryException::class, $e);
            $this->assertStringContainsString("{$first->id}, {$second->id}", $e->getMessage());
        }

        $this->assertFalse($this->uniqueIndexExists());
        $this->assertSame(3, CarbonTarget::withTrashed()->whereKey([$first->id, $second->id, $deleted->id])->count());
    }
}
