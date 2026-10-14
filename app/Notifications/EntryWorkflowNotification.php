<?php

namespace App\Notifications;

use App\Models\CarbonEntry;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Notifikasi alur approval entri: entry_submitted (ke owner project), entry_approved &
 * entry_rejected (ke pembuat entri). Selalu in-app; juga email bila user tidak mematikannya.
 */
class EntryWorkflowNotification extends Notification implements ShouldQueue
{
    use Queueable;

    private const MESSAGES = [
        'entry_submitted' => ':actor mengirim entri untuk di-approve di project :project.',
        'entry_approved'  => ':actor menyetujui entri Anda di project :project.',
        'entry_rejected'  => ':actor menolak entri Anda di project :project.',
    ];

    private const SUBJECTS = [
        'entry_submitted' => 'Entri menunggu approval: :project',
        'entry_approved'  => 'Entri disetujui: :project',
        'entry_rejected'  => 'Entri ditolak: :project',
    ];

    /** Email dibatalkan (bukan gagal berulang) bila entri/user sudah dihapus permanen saat worker berjalan */
    public bool $deleteWhenMissingModels = true;

    /** Percobaan kirim email bila server SMTP sedang gangguan */
    public int $tries = 3;

    public function __construct(
        private string $type,
        private CarbonEntry $entry,
        private User $actor,
        private ?string $reason = null,
    ) {
        // Dikirim setelah transaksi yang memicunya selesai (tidak terkirim bila transaksi dibatalkan)
        $this->afterCommit();
    }

    /** @return array<int, int> detik jeda sebelum percobaan ke-2 dan ke-3 */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function via(object $notifiable): array
    {
        // Email hanya untuk akun aktif yang tidak mematikannya di Profil
        $wantsEmail = $notifiable instanceof User && $notifiable->is_active && $notifiable->email_notifications;

        return $wantsEmail ? ['database', 'mail'] : ['database'];
    }

    /**
     * In-app tetap disimpan saat itu juga (lonceng langsung terisi); email lewat antrean default
     * supaya request approval tidak menunggu server SMTP.
     *
     * @return array<string, string>
     */
    public function viaConnections(): array
    {
        return ['database' => 'sync'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type'         => $this->type,
            'entry_id'     => $this->entry->id,
            'project_id'   => $this->entry->project_id,
            'project_name' => $this->entry->project?->name,
            'actor_name'   => $this->actor->name,
            'reason'       => $this->reason,
            'message'      => $this->message(),
        ];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(strtr(self::SUBJECTS[$this->type], [':project' => $this->projectName()]))
            ->greeting('Halo '.self::plain($notifiable->name).',')
            ->line(self::plain($this->message()));

        if ($this->reason !== null) {
            $mail->line('Alasan: '.self::plain($this->reason));
        }

        return $mail
            ->action('Buka project', rtrim((string) config('app.frontend_url'), '/')."/projects/{$this->entry->project_id}")
            ->line('Anda menerima email ini karena notifikasi email aktif. Notifikasi email bisa dimatikan di halaman Profil.');
    }

    /**
     * Isi email dirender sebagai markdown: teks bebas (alasan, nama) di-escape supaya tidak bisa
     * menyisipkan tautan atau format, mis. "[klik](https://...)".
     */
    private static function plain(string $text): string
    {
        return preg_replace('/([\\\\`*_\[\]()#<>])/', '\\\\$1', $text);
    }

    private function message(): string
    {
        return strtr(self::MESSAGES[$this->type], [':actor' => $this->actor->name, ':project' => $this->projectName()]);
    }

    private function projectName(): string
    {
        return (string) $this->entry->project?->name;
    }
}
