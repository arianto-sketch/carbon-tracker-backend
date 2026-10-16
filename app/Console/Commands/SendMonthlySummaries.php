<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\MonthlySummaryNotification;
use App\Services\MonthlySummaryService;
use App\Support\Format;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Email ringkasan emisi bulan lalu ke owner project dan admin (dijalankan tanggal 1 lewat scheduler).
 *
 * Periode terakhir yang terkirim dicatat per user, jadi run ulang tidak mengirim dobel. Periode yang
 * lebih lama (susulan lewat --month) hanya dikirim dengan --force, dan tidak memundurkan catatan itu.
 * Catatan ditulis saat email masuk antrean: bila job gagal di semua percobaan, kirim ulang dengan --force.
 */
class SendMonthlySummaries extends Command
{
    protected $signature = 'reports:send-monthly-summaries
        {--month= : Periode YYYY-MM (default: bulan lalu menurut zona waktu bisnis)}
        {--force : Kirim ulang walau periode ini sudah pernah dikirim}
        {--dry-run : Tampilkan penerima tanpa mengirim}';

    protected $description = 'Kirim email ringkasan emisi bulanan ke owner project dan admin';

    public function handle(MonthlySummaryService $service): int
    {
        $month = $this->month();
        if ($month === null) {
            $this->error('Format --month harus YYYY-MM, mis. 2026-10.');

            return self::FAILURE;
        }

        $period = $month->format('Y-m');
        $dryRun = (bool) $this->option('dry-run');
        $sent = 0;
        $failed = 0;

        $service->recipients()
            // YYYY-MM bisa dibandingkan sebagai string
            ->when(! $this->option('force'), fn ($q) => $q->where(fn ($w) => $w
                ->whereNull('last_monthly_summary_period')
                ->orWhere('last_monthly_summary_period', '<', $period)))
            ->chunkById(100, function ($users) use ($service, $month, $period, $dryRun, &$sent, &$failed) {
                /** @var User $user */
                foreach ($users as $user) {
                    // Satu penerima yang gagal tidak menghentikan pengiriman ke penerima lain
                    try {
                        $summary = $service->build($user, $month);
                        if ($summary === null) {
                            continue;
                        }

                        if ($dryRun) {
                            $this->line("{$user->email}: {$summary['project_count']} project, ".Format::co2($summary['total_co2e_kg']));
                        } else {
                            $user->notify(new MonthlySummaryNotification($summary));
                            if ($user->last_monthly_summary_period === null || $user->last_monthly_summary_period < $period) {
                                $user->forceFill(['last_monthly_summary_period' => $period])->save();
                            }
                        }
                        $sent++;
                    } catch (Throwable $e) {
                        report($e);
                        $failed++;
                        $this->error("Gagal menyiapkan ringkasan untuk user #{$user->id}: {$e->getMessage()}");
                    }
                }
            });

        $label = $month->format('m/Y');
        $this->info($dryRun
            ? "{$sent} penerima akan menerima ringkasan {$label} (dry run, tidak ada email terkirim)."
            : "Ringkasan {$label} dikirim ke {$sent} penerima.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** Hari pertama bulan yang diringkas; null bila --month tidak valid */
    private function month(): ?CarbonImmutable
    {
        $timezone = config('app.business_timezone');
        $option = $this->option('month');

        if ($option === null) {
            return CarbonImmutable::now($timezone)->startOfMonth()->subMonthNoOverflow();
        }

        if (! preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $option, $m)) {
            return null;
        }

        return CarbonImmutable::create((int) $m[1], (int) $m[2], 1, 0, 0, 0, $timezone);
    }
}
