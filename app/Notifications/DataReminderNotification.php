<?php

namespace App\Notifications;

use App\Models\User;
use App\Support\Markdown;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Pengingat data bulan lalu: satu notifikasi per user per putaran (bukan per project), berisi project
 * yang datanya belum lengkap dan entri miliknya yang belum diajukan. In-app selalu; email bila user aktif
 * dan tidak mematikan notifikasi email di Profil (sama dengan notifikasi approval).
 */
class DataReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** Percobaan kirim email bila server SMTP sedang gangguan */
    public int $tries = 3;

    /**
     * @param  array{period_label: string, round: int, projects: list<array{id: int, name: string, empty: bool, missing_factors: list<string>}>, unsubmitted: list<array{project_id: int, project_name: string, count: int}>}  $reminder
     */
    public function __construct(public array $reminder) {}

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function via(object $notifiable): array
    {
        $wantsEmail = $notifiable instanceof User && $notifiable->is_active && $notifiable->email_notifications;

        return $wantsEmail ? ['database', 'mail'] : ['database'];
    }

    /** In-app disimpan saat itu juga; email lewat antrean default */
    public function viaConnections(): array
    {
        return ['database' => 'sync'];
    }

    public function toArray(object $notifiable): array
    {
        $projects = collect($this->reminder['projects'])->map(fn (array $p) => ['id' => $p['id'], 'name' => $p['name']])
            ->merge(collect($this->reminder['unsubmitted'])->map(fn (array $u) => ['id' => $u['project_id'], 'name' => $u['project_name']]))
            ->unique('id')
            ->values();
        // Satu project: langsung ke project itu; beberapa project: ke dashboard (kartu kelengkapan data)
        $single = $projects->count() === 1 ? $projects->first() : null;

        return [
            'type'         => 'data_reminder',
            'entry_id'     => null,
            'project_id'   => $single['id'] ?? null,
            'project_name' => $single['name'] ?? null,
            'link'         => $single ? "/projects/{$single['id']}" : '/',
            'message'      => $this->message(),
        ];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $period = $this->reminder['period_label'];
        $frontend = rtrim((string) config('app.frontend_url'), '/');
        $link = $this->toArray($notifiable)['link'];

        $mail = (new MailMessage)
            ->subject(($this->isSecondRound() ? 'Pengingat: ' : '')."Data emisi {$period} belum lengkap")
            ->greeting('Halo '.Markdown::escape($notifiable->name).',');

        if ($this->reminder['projects'] !== []) {
            $mail->line("Data {$period} berikut belum dicatat:");
            foreach ($this->reminder['projects'] as $project) {
                $mail->line('- **'.Markdown::escape($project['name']).'**: '.Markdown::escape($this->describe($project)));
            }
        }

        if ($this->reminder['unsubmitted'] !== []) {
            $mail->line("Entri {$period} Anda yang belum diajukan (draft atau ditolak):");
            foreach ($this->reminder['unsubmitted'] as $item) {
                $mail->line('- **'.Markdown::escape($item['project_name'])."**: {$item['count']} entri");
            }
        }

        return $mail
            ->action($link === '/' ? 'Buka dashboard' : 'Buka project', $frontend.$link)
            ->line('Bila memang tidak ada data bulan itu, tandai "tidak ada data" di tab Kelengkapan project agar tidak diingatkan lagi.')
            ->line('Notifikasi email bisa dimatikan di halaman Profil.');
    }

    private function message(): string
    {
        $period = $this->reminder['period_label'];
        $projects = $this->reminder['projects'];
        $parts = [];

        if (count($projects) === 1) {
            $parts[] = "Data {$period} di project {$projects[0]['name']}: {$this->describe($projects[0])}.";
        } elseif (count($projects) > 1) {
            $parts[] = "Data {$period} belum lengkap di ".count($projects).' project.';
        }

        $unsubmitted = array_sum(array_column($this->reminder['unsubmitted'], 'count'));
        if ($unsubmitted > 0) {
            $parts[] = "{$unsubmitted} entri {$period} Anda belum diajukan.";
        }

        return ($this->isSecondRound() ? 'Pengingat kedua: ' : '').implode(' ', $parts);
    }

    /** @param  array{empty: bool, missing_factors: list<string>}  $project */
    private function describe(array $project): string
    {
        $factors = implode(', ', $project['missing_factors']);

        if ($project['empty']) {
            return 'belum ada entri sama sekali'.($factors !== '' ? " (biasanya: {$factors})" : '');
        }

        return "belum ada {$factors}";
    }

    private function isSecondRound(): bool
    {
        return (int) $this->reminder['round'] === 2;
    }
}
