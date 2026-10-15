<?php

namespace App\Console\Commands;

use App\Models\EmissionFactor;
use Illuminate\Console\Command;

/**
 * emission_factors.factor_value = nilai yang berlaku hari ini. Saat periode yang dijadwalkan ke depan
 * mulai berlaku, kolom ini diperbarui (dijalankan harian lewat scheduler).
 */
class SyncFactorCurrentValues extends Command
{
    protected $signature = 'factors:sync-current-values';

    protected $description = 'Perbarui nilai faktor emisi "saat ini" sesuai periode yang berlaku hari ini';

    public function handle(): int
    {
        $today = now(config('app.business_timezone'))->toDateString();
        $updated = 0;

        EmissionFactor::with('values')->chunkById(100, function ($factors) use ($today, &$updated) {
            foreach ($factors as $factor) {
                $current = $factor->valueOn($today);

                if ((string) $factor->factor_value !== $current) {
                    $factor->update(['factor_value' => $current]);
                    $updated++;
                }
            }
        });

        $this->info("{$updated} faktor emisi diperbarui ke nilai yang berlaku {$today}.");

        return self::SUCCESS;
    }
}
