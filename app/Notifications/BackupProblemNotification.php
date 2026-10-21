<?php

namespace App\Notifications;

use App\Support\Markdown;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Peringatan operasional ke admin: dikirim langsung (tidak lewat antrean), supaya tetap sampai
 * walau queue worker ikut bermasalah. Tidak mengikuti preferensi notifikasi email di Profil.
 */
class BackupProblemNotification extends Notification
{
    /** @param  array<string, string>  $problems  kode => penjelasan (BackupStatusService::problems) */
    public function __construct(public array $problems) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->error()
            ->subject('['.config('app.name').'] Backup bermasalah')
            ->greeting('Backup bermasalah')
            ->line('Pemeriksaan backup otomatis menemukan masalah berikut:');

        foreach ($this->problems as $problem) {
            // Isi status (mis. pesan error) berasal dari file di server: di-escape agar tidak menjadi tautan/HTML
            $mail->line('- '.Markdown::escape($problem));
        }

        return $mail
            ->line('Periksa di server dengan `make ps`, `make logs SERVICE=backup`, dan `make logs SERVICE=offsite` (lihat docs/DEPLOY.md).')
            ->line('Email ini dikirim sekali per masalah; email berikutnya dikirim bila muncul masalah lain atau saat backup kembali normal.');
    }
}
