<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Unique index lama ikut menghitung baris soft-deleted (target yang dihapus tidak bisa
 * dibuat ulang -> 500) dan tidak mencegah duplikat saat category_id/period_value NULL.
 * Keunikan kini dijaga di CarbonTargetService::ensureUniquePeriod().
 */
return new class extends Migration
{
    private const UNIQUE_COLUMNS = ['project_id', 'category_id', 'period_type', 'period_year', 'period_value'];

    public function up(): void
    {
        Schema::table('carbon_targets', function (Blueprint $table) {
            // Pastikan FK project_id tetap punya index sendiri sebelum index komposit dihapus (MySQL)
            $table->index('project_id', 'carbon_targets_project_id_index');
            $table->dropUnique('carbon_targets_unique');
        });
    }

    public function down(): void
    {
        $this->removeConflictingSoftDeletedTargets();

        Schema::table('carbon_targets', function (Blueprint $table) {
            $table->unique(self::UNIQUE_COLUMNS, 'carbon_targets_unique');
            $table->dropIndex('carbon_targets_project_id_index');
        });
    }

    /**
     * Setelah migration ini, target bisa dihapus (soft delete) lalu dibuat ulang untuk periode yang sama,
     * sehingga unique index lama tidak bisa dipasang ulang. Target soft-deleted yang bentrok dihapus permanen
     * (skema lama memang tidak bisa menyimpannya): yang aktif dipertahankan, kalau semuanya terhapus yang
     * terbaru dipertahankan. Duplikat di antara target aktif tidak disentuh; rollback dihentikan lebih dulu.
     */
    private function removeConflictingSoftDeletedTargets(): void
    {
        // NULL tidak pernah bentrok di unique index (MySQL & SQLite)
        $groups = DB::table('carbon_targets')
            ->whereNotNull('category_id')->whereNotNull('period_value')
            ->select(self::UNIQUE_COLUMNS)->groupBy(self::UNIQUE_COLUMNS)
            ->havingRaw('COUNT(*) > 1')
            ->get();

        $toDelete = [];
        $activeConflicts = [];

        foreach ($groups as $group) {
            $rows = DB::table('carbon_targets')->where((array) $group)->get(['id', 'deleted_at'])
                ->sortBy([fn ($a, $b) => ($a->deleted_at !== null) <=> ($b->deleted_at !== null), ['id', 'desc']])
                ->values();
            $activeIds = $rows->whereNull('deleted_at')->pluck('id')->sort()->values();

            if ($activeIds->count() > 1) {
                $activeConflicts[] = $activeIds->implode(', ');

                continue;
            }

            array_push($toDelete, ...$rows->slice(1)->pluck('id')->all());
        }

        if ($activeConflicts) {
            throw new RuntimeException(
                'Rollback dibatalkan: ada target aktif dengan project, kategori, dan periode yang sama (id: '
                .implode('; ', $activeConflicts).'). Rapikan target tersebut secara manual, lalu ulangi rollback.'
            );
        }

        DB::table('carbon_targets')->whereIn('id', $toDelete)->delete();
    }
};
