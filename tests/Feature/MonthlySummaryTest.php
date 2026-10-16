<?php

namespace Tests\Feature;

use App\Models\CarbonTarget;
use App\Models\Project;
use App\Models\User;
use App\Notifications\MonthlySummaryNotification;
use App\Services\MonthlySummaryService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsCarbonData;
use Tests\TestCase;

/**
 * Tanggal 1 setiap bulan, owner project dan admin menerima email ringkasan emisi bulan lalu:
 * total vs bulan sebelumnya, rincian per project, entri menunggu review, dan target yang hampir/sudah terlampaui.
 */
class MonthlySummaryTest extends TestCase
{
    use BuildsCarbonData, RefreshDatabase;

    private User $owner;

    private User $member;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMasterData();
        $this->owner = User::factory()->create(['role' => 'pm', 'name' => 'Owner Satu']);
        $this->member = User::factory()->create(['role' => 'pm']);
        $this->project = $this->makeProject($this->owner);
        $this->addMember($this->project, $this->member);

        // Run terjadwal: 1 November 2026 pukul 07.00 WIB -> ringkasan Oktober 2026
        $this->travelTo(Carbon::parse('2026-11-01 07:00', 'Asia/Jakarta'));
    }

    /** 231 kg di September, 462 kg di Oktober */
    private function seedTwoMonths(): void
    {
        $this->makeApprovedEntry($this->project, $this->member, 100, '2026-09-10');
        $this->makeApprovedEntry($this->project, $this->member, 200, '2026-10-10');
    }

    public function test_owners_receive_last_months_summary_of_their_projects(): void
    {
        $this->seedTwoMonths();
        $this->makeEntry($this->project, $this->member, 'submitted');
        CarbonTarget::create([
            'project_id' => $this->project->id, 'period_type' => 'yearly', 'period_year' => 2026,
            'target_co2e_kg' => 500, 'created_by' => $this->owner->id,
        ]);
        Notification::fake();

        $this->artisan('reports:send-monthly-summaries')->assertSuccessful();

        Notification::assertSentTo($this->owner, MonthlySummaryNotification::class, function (MonthlySummaryNotification $n, array $channels) {
            $s = $n->summary;

            return $channels === ['mail']
                && $s['period'] === '2026-10'
                && $s['period_label'] === 'Oktober 2026'
                && $s['total_co2e_kg'] == 462
                && $s['previous_total_co2e_kg'] == 231
                && $s['change_pct'] == 100
                && $s['projects'][0]['name'] === $this->project->name
                && $s['projects'][0]['total_co2e_kg'] == 462
                && $s['pending_review_count'] === 1
                && $s['target_alerts'][0]['level'] === 'exceeded';
        });
        $this->assertSame('2026-10', $this->owner->fresh()->last_monthly_summary_period);
    }

    public function test_members_viewers_inactive_and_opted_out_users_get_nothing(): void
    {
        $this->seedTwoMonths();
        $optedOut = User::factory()->create(['role' => 'pm', 'monthly_summary_email' => false]);
        $this->makeProject($optedOut, 'PRJ-2');
        $inactive = User::factory()->create(['role' => 'pm', 'is_active' => false]);
        $this->makeProject($inactive, 'PRJ-3');
        $viewer = User::factory()->create(['role' => 'viewer']);
        $this->addMember($this->project, $viewer, 'viewer');
        // Owner yang semua project-nya sudah diarsipkan
        $archivedOwner = User::factory()->create(['role' => 'pm']);
        $this->makeProject($archivedOwner, 'PRJ-4')->update(['status' => 'archived']);
        Notification::fake();

        $this->artisan('reports:send-monthly-summaries')->assertSuccessful();

        Notification::assertSentTo($this->owner, MonthlySummaryNotification::class);
        Notification::assertNotSentTo([$this->member, $optedOut, $inactive, $viewer, $archivedOwner], MonthlySummaryNotification::class);
    }

    public function test_admins_receive_a_summary_of_all_projects(): void
    {
        $this->seedTwoMonths();
        $otherOwner = User::factory()->create(['role' => 'pm']);
        $other = $this->makeProject($otherOwner, 'PRJ-2');
        $this->makeApprovedEntry($other, $otherOwner, 50, '2026-10-05');
        $admin = User::factory()->create(['role' => 'admin']);
        Notification::fake();

        $this->artisan('reports:send-monthly-summaries')->assertSuccessful();

        Notification::assertSentTo($admin, MonthlySummaryNotification::class, fn (MonthlySummaryNotification $n) => count($n->summary['projects']) === 2
            && $n->summary['total_co2e_kg'] == 577.5);
        // Owner hanya melihat project miliknya
        Notification::assertSentTo($this->owner, MonthlySummaryNotification::class, fn (MonthlySummaryNotification $n) => count($n->summary['projects']) === 1);
    }

    public function test_summary_is_sent_once_per_period_unless_forced(): void
    {
        Notification::fake();

        $this->artisan('reports:send-monthly-summaries')->assertSuccessful();
        $this->artisan('reports:send-monthly-summaries')->assertSuccessful();
        Notification::assertSentToTimes($this->owner, MonthlySummaryNotification::class, 1);

        $this->artisan('reports:send-monthly-summaries --force')->assertSuccessful();
        Notification::assertSentToTimes($this->owner, MonthlySummaryNotification::class, 2);
    }

    public function test_a_specific_month_can_be_sent(): void
    {
        $this->seedTwoMonths();
        Notification::fake();

        $this->artisan('reports:send-monthly-summaries --month=2026-09')->assertSuccessful();

        Notification::assertSentTo($this->owner, MonthlySummaryNotification::class, fn (MonthlySummaryNotification $n) => $n->summary['period'] === '2026-09'
            && $n->summary['total_co2e_kg'] == 231
            && $n->summary['change_pct'] === null);
    }

    public function test_backfilling_an_older_month_needs_force_and_keeps_the_latest_marker(): void
    {
        Notification::fake();
        $this->artisan('reports:send-monthly-summaries')->assertSuccessful();

        $this->artisan('reports:send-monthly-summaries --month=2026-09')->assertSuccessful();
        Notification::assertSentToTimes($this->owner, MonthlySummaryNotification::class, 1);

        $this->artisan('reports:send-monthly-summaries --month=2026-09 --force')->assertSuccessful();
        Notification::assertSentToTimes($this->owner, MonthlySummaryNotification::class, 2);
        // Run terjadwal berikutnya untuk Oktober tetap dianggap sudah terkirim
        $this->assertSame('2026-10', $this->owner->fresh()->last_monthly_summary_period);
    }

    public function test_target_alerts_are_capped_with_a_count_of_the_rest(): void
    {
        foreach (range(1, MonthlySummaryService::MAX_ALERTS + 1) as $i) {
            $project = $this->makeProject($this->owner, "ALR-{$i}");
            $this->makeApprovedEntry($project, $this->owner, 100, '2026-10-10');
            CarbonTarget::create([
                'project_id' => $project->id, 'period_type' => 'yearly', 'period_year' => 2026,
                'target_co2e_kg' => 100, 'created_by' => $this->owner->id,
            ]);
        }
        Notification::fake();

        $this->artisan('reports:send-monthly-summaries')->assertSuccessful();

        $notification = Notification::sent($this->owner, MonthlySummaryNotification::class)->first();
        $this->assertCount(MonthlySummaryService::MAX_ALERTS, $notification->summary['target_alerts']);
        $this->assertSame(MonthlySummaryService::MAX_ALERTS + 1, $notification->summary['alert_count']);
        $this->assertStringContainsString('Dan 1 target lainnya', (string) $notification->toMail($this->owner)->render());
    }

    public function test_invalid_month_is_rejected(): void
    {
        Notification::fake();

        $this->artisan('reports:send-monthly-summaries --month=2026-13')->assertFailed();
        $this->artisan('reports:send-monthly-summaries --month=bulan-lalu')->assertFailed();
        Notification::assertNothingSent();
    }

    public function test_dry_run_lists_recipients_without_sending(): void
    {
        Notification::fake();

        $this->artisan('reports:send-monthly-summaries --dry-run')
            ->expectsOutputToContain($this->owner->email)
            ->assertSuccessful();

        Notification::assertNothingSent();
        $this->assertNull($this->owner->fresh()->last_monthly_summary_period);
    }

    public function test_email_shows_totals_projects_and_profile_link(): void
    {
        $this->project->update(['name' => 'Gedung *A* | Lantai [2]']);
        $this->seedTwoMonths();
        Notification::fake();
        $this->artisan('reports:send-monthly-summaries')->assertSuccessful();

        $notification = Notification::sent($this->owner, MonthlySummaryNotification::class)->first();
        $mail = $notification->toMail($this->owner);
        $html = (string) $mail->render();

        $this->assertSame('Ringkasan emisi Oktober 2026', $mail->subject);
        $this->assertStringContainsString('462.00 kg CO₂e', $html);
        $this->assertStringContainsString('+100,0%', $html);
        // Nama project tampil apa adanya (karakter markdown/tabel di-escape, bukan ditafsirkan)
        $this->assertStringContainsString('Gedung *A* | Lantai [2]', $html);
        $this->assertStringContainsString('/profile', $html);
    }

    public function test_users_can_turn_the_summary_off_from_profile(): void
    {
        $this->actingAs($this->owner, 'sanctum')->getJson('/api/v1/auth/me')
            ->assertJsonPath('data.monthly_summary_email', true);

        $this->actingAs($this->owner, 'sanctum')->putJson('/api/v1/auth/me', ['name' => 'Owner Satu', 'monthly_summary_email' => false])
            ->assertOk()
            ->assertJsonPath('data.monthly_summary_email', false);

        $this->assertFalse($this->owner->fresh()->monthly_summary_email);
    }

    public function test_it_is_scheduled_on_the_first_of_each_month_in_business_time(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->command, 'reports:send-monthly-summaries'));

        $this->assertNotNull($event);
        $this->assertSame('0 7 1 * *', $event->expression);
        $this->assertSame('Asia/Jakarta', $event->timezone);
    }
}
