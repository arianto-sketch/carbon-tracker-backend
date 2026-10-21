<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Penutup BackupProblemNotification: backup harian dan off-site kembali normal (dikirim langsung) */
class BackupRecoveredNotification extends Notification
{
    public function __construct(public string $summary) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->success()
            ->subject('['.config('app.name').'] Backup kembali normal')
            ->greeting('Backup kembali normal')
            ->line($this->summary);
    }
}
