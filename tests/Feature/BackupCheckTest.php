<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\BackupProblemNotification;
use App\Notifications\BackupRecoveredNotification;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Service backup dan offsite menulis status ke volume bersama; scheduler memeriksanya tiap jam dan
 * mengirim email ke admin aktif saat ada masalah baru, lalu sekali lagi saat kondisi pulih.
 */
class BackupCheckTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/backup-status-'.uniqid();
        File::makeDirectory($this->dir);
        config(['backups.status_path' => $this->dir]);
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->travelTo(Carbon::parse('2026-10-21 10:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function writeStatus(string $name, array $data): void
    {
        File::put("{$this->dir}/{$name}.json", json_encode($data));
    }

    private function healthy(): void
    {
        $this->writeStatus('backup', ['last_attempt_at' => '2026-10-20T19:00:05Z', 'last_success_at' => '2026-10-20T19:00:05Z', 'last_error' => null]);
        $this->writeStatus('offsite', ['enabled' => true, 'last_attempt_at' => '2026-10-21T09:45:00Z', 'last_success_at' => '2026-10-21T09:45:00Z', 'last_error' => null]);
    }

    public function test_healthy_backups_send_nothing(): void
    {
        $this->healthy();
        Notification::fake();

        $this->artisan('backups:check')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_stale_backup_alerts_active_admins_once(): void
    {
        $this->healthy();
        $this->writeStatus('backup', ['last_attempt_at' => '2026-10-19T19:00:05Z', 'last_success_at' => '2026-10-19T19:00:05Z', 'last_error' => null]);
        $inactiveAdmin = User::factory()->create(['role' => 'admin', 'is_active' => false]);
        $pm = User::factory()->create(['role' => 'pm']);
        Notification::fake();

        $this->artisan('backups:check')->assertFailed();
        $this->artisan('backups:check')->assertFailed();

        Notification::assertSentToTimes($this->admin, BackupProblemNotification::class, 1);
        Notification::assertSentTo($this->admin, BackupProblemNotification::class,
            fn (BackupProblemNotification $n) => array_keys($n->problems) === ['backup_stale']);
        Notification::assertNotSentTo([$inactiveAdmin, $pm], BackupProblemNotification::class);
    }

    public function test_a_failed_daily_backup_is_reported_with_its_error(): void
    {
        $this->healthy();
        $this->writeStatus('backup', ['last_attempt_at' => '2026-10-20T19:00:05Z', 'last_success_at' => '2026-10-19T19:00:05Z', 'last_error' => 'backup database atau lampiran gagal']);
        Notification::fake();

        $this->artisan('backups:check')->assertFailed();

        Notification::assertSentTo($this->admin, BackupProblemNotification::class,
            fn (BackupProblemNotification $n) => isset($n->problems['backup_failed'])
                && str_contains($n->problems['backup_failed'], 'backup database atau lampiran gagal'));
    }

    public function test_a_new_problem_sends_a_new_alert_and_recovery_is_announced_once(): void
    {
        $this->healthy();
        $this->writeStatus('offsite', ['enabled' => true, 'last_attempt_at' => '2026-10-21T09:45:00Z', 'last_success_at' => '2026-10-21T01:00:00Z', 'last_error' => 'tidak bisa membaca daftar file di remote']);
        Notification::fake();
        $this->artisan('backups:check')->assertFailed();

        $this->writeStatus('backup', ['last_attempt_at' => '2026-10-19T19:00:05Z', 'last_success_at' => '2026-10-19T19:00:05Z', 'last_error' => null]);
        $this->artisan('backups:check')->assertFailed();
        Notification::assertSentToTimes($this->admin, BackupProblemNotification::class, 2);

        $this->healthy();
        $this->artisan('backups:check')->assertSuccessful();
        $this->artisan('backups:check')->assertSuccessful();
        Notification::assertSentToTimes($this->admin, BackupRecoveredNotification::class, 1);
    }

    public function test_offsite_failures_are_tolerated_until_the_offsite_threshold(): void
    {
        $this->healthy();
        // Satu kali sinkron gagal (mis. jaringan putus sesaat): belum dilaporkan selama sukses terakhir < 6 jam
        $this->writeStatus('offsite', ['enabled' => true, 'last_attempt_at' => '2026-10-21T09:45:00Z', 'last_success_at' => '2026-10-21T09:15:00Z', 'last_error' => 'timeout']);
        Notification::fake();

        $this->artisan('backups:check')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_disabled_offsite_is_not_checked_but_a_missing_status_is(): void
    {
        $this->healthy();
        $this->writeStatus('offsite', ['enabled' => false, 'last_attempt_at' => '2026-10-21T09:00:00Z', 'last_success_at' => null, 'last_error' => null]);
        Notification::fake();
        $this->artisan('backups:check')->assertSuccessful();
        Notification::assertNothingSent();

        File::delete("{$this->dir}/offsite.json");
        File::delete("{$this->dir}/backup.json");
        $this->artisan('backups:check')->assertFailed();
        Notification::assertSentTo($this->admin, BackupProblemNotification::class,
            fn (BackupProblemNotification $n) => array_keys($n->problems) === ['backup_never', 'offsite_missing']);
    }

    public function test_a_resolved_part_of_a_reported_problem_sends_no_new_alert(): void
    {
        $this->healthy();
        $this->writeStatus('backup', ['last_attempt_at' => '2026-10-19T19:00:05Z', 'last_success_at' => '2026-10-19T19:00:05Z', 'last_error' => null]);
        $this->writeStatus('offsite', ['enabled' => true, 'last_attempt_at' => '2026-10-21T09:45:00Z', 'last_success_at' => null, 'last_error' => 'bucket tidak ditemukan']);
        Notification::fake();
        $this->artisan('backups:check')->assertFailed();

        // Off-site pulih, backup harian masih terlambat: tidak ada email baru
        $this->writeStatus('offsite', ['enabled' => true, 'last_attempt_at' => '2026-10-21T09:45:00Z', 'last_success_at' => '2026-10-21T09:45:00Z', 'last_error' => null]);
        $this->artisan('backups:check')->assertFailed();

        Notification::assertSentToTimes($this->admin, BackupProblemNotification::class, 1);
        Notification::assertSentTo($this->admin, BackupProblemNotification::class,
            fn (BackupProblemNotification $n) => str_contains($n->problems['offsite_stale'], 'Error terakhir: bucket tidak ditemukan'));
    }

    public function test_a_failed_send_is_retried_on_the_next_check(): void
    {
        $this->healthy();
        $this->writeStatus('backup', ['last_attempt_at' => '2026-10-19T19:00:05Z', 'last_success_at' => '2026-10-19T19:00:05Z', 'last_error' => null]);
        Notification::partialMock()->shouldReceive('sendNow')->once()->andThrow(new \RuntimeException('SMTP tidak bisa dihubungi'));

        try {
            $this->artisan('backups:check')->run();
            $this->fail('Kegagalan kirim email seharusnya tidak ditelan');
        } catch (\RuntimeException $e) {
            $this->assertSame('SMTP tidak bisa dihubungi', $e->getMessage());
        }

        Notification::fake();
        $this->artisan('backups:check')->assertFailed();
        Notification::assertSentTo($this->admin, BackupProblemNotification::class);
    }

    public function test_problems_are_reported_once_an_admin_exists(): void
    {
        $this->admin->update(['is_active' => false]);
        $this->healthy();
        $this->writeStatus('backup', ['last_attempt_at' => '2026-10-19T19:00:05Z', 'last_success_at' => '2026-10-19T19:00:05Z', 'last_error' => null]);
        Notification::fake();

        $this->artisan('backups:check')->expectsOutputToContain('Tidak ada admin aktif')->assertFailed();

        $newAdmin = User::factory()->create(['role' => 'admin']);
        $this->artisan('backups:check')->assertFailed();
        Notification::assertSentTo($newAdmin, BackupProblemNotification::class);
    }

    public function test_corrupt_status_files_are_reported_as_such(): void
    {
        $this->healthy();
        File::put("{$this->dir}/backup.json", '{"last_attempt_at": "2026-10-20T19:00:05Z", rusak');
        Notification::fake();

        $this->artisan('backups:check')->assertFailed();

        Notification::assertSentTo($this->admin, BackupProblemNotification::class,
            fn (BackupProblemNotification $n) => array_keys($n->problems) === ['backup_status_invalid']);
    }

    public function test_check_is_skipped_without_a_status_path(): void
    {
        config(['backups.status_path' => null]);
        Notification::fake();

        $this->artisan('backups:check')
            ->expectsOutputToContain('dilewati')
            ->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_alert_email_lists_problems_and_escapes_status_text(): void
    {
        $this->healthy();
        $this->writeStatus('backup', ['last_attempt_at' => '2026-10-20T19:00:05Z', 'last_success_at' => '2026-10-19T19:00:05Z', 'last_error' => 'gagal [klik](https://contoh.test) <b>x</b>']);
        Notification::fake();
        $this->artisan('backups:check');

        $notification = Notification::sent($this->admin, BackupProblemNotification::class)->first();
        $mail = $notification->toMail($this->admin);
        $html = (string) $mail->render();

        $this->assertStringContainsString('Backup bermasalah', $mail->subject);
        $this->assertStringContainsString('make logs SERVICE=backup', $html);
        $this->assertStringNotContainsString('href="https://contoh.test"', $html);
        $this->assertStringNotContainsString('<b>x</b>', $html);
    }

    public function test_it_runs_hourly(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'backups:check'));

        $this->assertNotNull($event);
        $this->assertSame('0 * * * *', $event->expression);
    }
}
