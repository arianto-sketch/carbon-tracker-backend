<?php

namespace App\Notifications;

use App\Models\User;
use App\Support\Markdown;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Dasar notifikasi alur approval: selalu in-app (sinkron), juga email lewat antrean bila user aktif
 * dan tidak mematikannya di Profil.
 */
abstract class ApprovalNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** Email dibatalkan (bukan gagal berulang) bila entri/user sudah dihapus permanen saat worker berjalan */
    public bool $deleteWhenMissingModels = true;

    /** Percobaan kirim email bila server SMTP sedang gangguan */
    public int $tries = 3;

    public function __construct()
    {
        // Dikirim setelah transaksi yang memicunya selesai (tidak terkirim bila transaksi dibatalkan)
        $this->afterCommit();
    }

    /** Kalimat utama, mis. "Owner menyetujui entri Anda di project X." */
    abstract protected function message(): string;

    abstract protected function subject(): string;

    abstract protected function projectId(): int;

    abstract protected function reason(): ?string;

    /** @return array<int, int> detik jeda sebelum percobaan ke-2 dan ke-3 */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function via(object $notifiable): array
    {
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

    public function toMail(User $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject($this->subject())
            ->greeting('Halo '.Markdown::escape($notifiable->name).',')
            ->line(Markdown::escape($this->message()));

        if ($this->reason() !== null) {
            $mail->line('Alasan: '.Markdown::escape((string) $this->reason()));
        }

        return $mail
            ->action('Buka project', rtrim((string) config('app.frontend_url'), '/')."/projects/{$this->projectId()}")
            ->line('Anda menerima email ini karena notifikasi email aktif. Notifikasi email bisa dimatikan di halaman Profil.');
    }
}
