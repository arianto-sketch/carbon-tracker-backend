<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Email ringkasan emisi bulanan (hanya email, lewat antrean) */
class MonthlySummaryNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** Percobaan kirim bila server SMTP sedang gangguan */
    public int $tries = 3;

    /** @param  array<string, mixed>  $summary  hasil MonthlySummaryService::build() */
    public function __construct(public array $summary) {}

    /** @return array<int, int> detik jeda sebelum percobaan ke-2 dan ke-3 */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /** Dicek ulang saat worker mengirim: user bisa dinonaktifkan atau mematikan ringkasan setelah dijadwalkan */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        return $notifiable instanceof User && $notifiable->is_active && $notifiable->monthly_summary_email;
    }

    public function toMail(User $notifiable): MailMessage
    {
        $frontend = rtrim((string) config('app.frontend_url'), '/');

        return (new MailMessage)
            ->subject('Ringkasan emisi '.$this->summary['period_label'])
            ->markdown('mail.monthly-summary', [
                'name'         => $notifiable->name,
                'summary'      => $this->summary,
                'dashboardUrl' => $frontend.'/',
                'profileUrl'   => $frontend.'/profile',
            ]);
    }
}
