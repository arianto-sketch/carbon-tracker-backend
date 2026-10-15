<?php

namespace App\Notifications;

use App\Support\Markdown;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Email link reset password (lupa password atau dikirim admin), lewat antrean.
 * Link mengarah ke halaman /reset-password di SPA, bukan ke route Laravel.
 */
class ResetPasswordNotification extends ResetPassword implements ShouldQueue
{
    use Queueable;

    /** Email dibatalkan bila user sudah dihapus saat worker berjalan */
    public bool $deleteWhenMissingModels = true;

    /** Email di antrean dibatalkan bila user dinonaktifkan sebelum terkirim */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        return (bool) ($notifiable->is_active ?? true);
    }

    public function toMail($notifiable): MailMessage
    {
        $minutes = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire');

        return (new MailMessage)
            ->subject('Reset password Carbon Tracker')
            ->greeting('Halo '.Markdown::escape((string) $notifiable->name).',')
            ->line('Kami menerima permintaan untuk mengatur ulang password akun Carbon Tracker Anda.')
            ->action('Atur password baru', $this->resetUrl($notifiable))
            ->line("Link ini berlaku {$minutes} menit dan hanya bisa dipakai sekali.")
            ->line('Abaikan email ini bila Anda tidak meminta reset password. Password Anda tidak berubah.');
    }

    protected function resetUrl($notifiable): string
    {
        // Token di fragment (#), bukan query: fragment tidak dikirim browser ke server, jadi token
        // tidak tercatat di access log nginx maupun header Referer
        return rtrim((string) config('app.frontend_url'), '/').'/reset-password#'.http_build_query([
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);
    }
}
