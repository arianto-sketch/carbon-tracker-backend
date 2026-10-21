<?php

namespace App\Console\Commands;

use App\Models\DataReminderRun;
use App\Models\ProjectMember;
use App\Models\User;
use App\Notifications\DataReminderNotification;
use App\Services\DataCompletenessService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Pengingat data bulan lalu (dijadwalkan tanggal 5 dan 10). Owner/member project diingatkan faktor rutin
 * yang belum dicatat dan project tanpa entri; pembuat entri diingatkan draft/ditolak yang belum diajukan.
 * Putaran 2 menghitung ulang, jadi yang sudah diisi atau ditandai "tidak ada data" tidak diingatkan lagi.
 */
class SendDataReminders extends Command
{
    protected $signature = 'entries:send-data-reminders
        {--round=1 : 1 = pengingat pertama (tanggal 5), 2 = pengingat ulang (tanggal 10)}
        {--month= : Periode YYYY-MM (default: bulan lalu menurut zona waktu bisnis)}
        {--force : Kirim walau putaran ini sudah pernah dijalankan}
        {--dry-run : Tampilkan penerima tanpa mengirim}';

    protected $description = 'Ingatkan data emisi bulan lalu yang belum dicatat atau belum diajukan';

    public function handle(DataCompletenessService $service): int
    {
        $round = (int) $this->option('round');
        $month = $this->month($service);
        if (! in_array($round, [1, 2], true) || $month === null) {
            $this->error('Pakai --round=1|2 dan --month=YYYY-MM (mis. 2026-10).');

            return self::FAILURE;
        }

        $period = $month->format('Y-m');
        $dryRun = (bool) $this->option('dry-run');
        if (! $this->option('force') && ! $dryRun && DataReminderRun::where('period', $period)->where('round', $round)->exists()) {
            $this->info("Pengingat putaran {$round} untuk {$period} sudah dikirim (pakai --force untuk mengulang).");

            return self::SUCCESS;
        }

        $reminders = $this->remindersPerUser($service, $month, $round);
        $users = User::whereIn('id', array_keys($reminders))->get()->keyBy('id');

        $sent = 0;
        $failed = 0;
        foreach ($reminders as $userId => $reminder) {
            $user = $users[$userId];
            if ($dryRun) {
                $this->line(sprintf('%s: %d project belum lengkap, %d entri belum diajukan', $user->email,
                    count($reminder['projects']), array_sum(array_column($reminder['unsubmitted'], 'count'))));

                continue;
            }
            // Satu penerima yang gagal tidak menghentikan pengiriman ke penerima lain
            try {
                $user->notify(new DataReminderNotification($reminder));
                $sent++;
            } catch (Throwable $e) {
                report($e);
                $failed++;
                $this->error("Gagal mengirim pengingat ke user #{$userId}: {$e->getMessage()}");
            }
        }

        // Dicatat walau ada yang gagal: --force mengirim ulang ke SEMUA penerima, jadi kegagalan dilaporkan
        // lewat log/Sentry dan kode keluar, bukan dengan mengulang putaran
        if (! $dryRun) {
            DataReminderRun::updateOrCreate(
                ['period' => $period, 'round' => $round],
                ['recipient_count' => $sent, 'ran_at' => now()],
            );
        }

        $this->info(($dryRun ? '(dry run) ' : '')."Pengingat putaran {$round} {$service->label($month)}: "
            .($dryRun ? count($reminders) : $sent).' penerima'.($failed ? ", {$failed} gagal" : '').'.');

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @return array<int, array{period_label: string, round: int, projects: list<array>, unsubmitted: list<array>}> */
    private function remindersPerUser(DataCompletenessService $service, CarbonImmutable $month, int $round): array
    {
        $projects = $service->expectedProjects($month)->get(['id', 'name', 'status', 'start_date', 'end_date']);
        $gaps = $service->gaps($projects, $month);

        // Hanya yang bisa mengisi: owner/member aktif yang bukan role global Viewer
        $writers = ProjectMember::query()
            ->whereIn('project_id', $projects->pluck('id'))
            ->whereIn('role', ['owner', 'member'])
            ->whereHas('user', fn ($q) => $q->where('is_active', true)->where('role', '!=', 'viewer'))
            ->get(['project_id', 'user_id'])
            ->groupBy('project_id');

        $empty = ['period_label' => $service->label($month), 'round' => $round, 'projects' => [], 'unsubmitted' => []];
        $reminders = [];

        foreach ($gaps as $gap) {
            foreach ($writers->get($gap['id'], collect()) as $member) {
                $reminders[$member->user_id] ??= $empty;
                $reminders[$member->user_id]['projects'][] = $gap;
            }
        }

        $names = $projects->pluck('name', 'id');
        foreach ($service->unsubmitted($projects->pluck('id')->all(), $month)->sortBy(fn ($row) => $names[$row->project_id]) as $row) {
            // Pembuat yang sudah keluar dari project atau tidak bisa mengajukan lagi tidak diingatkan
            if (! $writers->get($row->project_id, collect())->contains('user_id', $row->created_by)) {
                continue;
            }
            $reminders[$row->created_by] ??= $empty;
            $reminders[$row->created_by]['unsubmitted'][] = [
                'project_id' => (int) $row->project_id,
                'project_name' => $names[$row->project_id],
                'count' => (int) $row->entry_count,
            ];
        }

        return $reminders;
    }

    private function month(DataCompletenessService $service): ?CarbonImmutable
    {
        $option = $this->option('month');
        if ($option === null) {
            return $service->lastCompleteMonth();
        }
        if (! preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $option, $m)) {
            return null;
        }

        return CarbonImmutable::create((int) $m[1], (int) $m[2], 1, 0, 0, 0, config('app.business_timezone'));
    }
}
