<?php

namespace Tests\Feature;

use App\Models\DataGapDismissal;
use App\Models\EmissionFactor;
use App\Models\Project;
use App\Models\User;
use App\Notifications\DataReminderNotification;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsCarbonData;
use Tests\TestCase;

/**
 * Tanggal 5 (dan ulang tanggal 10 untuk yang masih kosong): owner/member diingatkan data bulan lalu
 * yang belum dicatat, pembuat entri diingatkan draft/ditolak yang belum diajukan.
 */
class DataReminderTest extends TestCase
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
        $this->member = User::factory()->create(['role' => 'pm', 'name' => 'Member Dua']);
        $this->project = $this->makeProject($this->owner);
        $this->addMember($this->project, $this->member);
        $this->travelTo(Carbon::parse('2026-11-05 08:00', 'Asia/Jakarta'));

        // Listrik rutin (Agustus & September) tapi Oktober kosong; bensin lengkap
        $this->makeApprovedEntry($this->project, $this->member, 500, '2026-08-20', 'electricity_jawa_bali');
        $this->makeApprovedEntry($this->project, $this->member, 520, '2026-09-20', 'electricity_jawa_bali');
        foreach (['2026-08-10', '2026-09-10', '2026-10-10'] as $date) {
            $this->makeApprovedEntry($this->project, $this->member, 40, $date);
        }
    }

    private function draft(User $creator, string $status = 'draft', string $date = '2026-10-15'): void
    {
        $this->makeApprovedEntry($this->project, $creator, 5, $date, 'paper_a4_ream')
            ->update(['status' => $status, 'approved_by' => null, 'approved_at' => null]);
    }

    public function test_project_writers_are_reminded_about_missing_recurring_data(): void
    {
        $viewer = User::factory()->create(['role' => 'pm']);
        $this->addMember($this->project, $viewer, 'viewer');
        $inactive = User::factory()->create(['role' => 'pm', 'is_active' => false]);
        $this->addMember($this->project, $inactive);
        Notification::fake();

        $this->artisan('entries:send-data-reminders')->assertSuccessful();

        foreach ([$this->owner, $this->member] as $user) {
            Notification::assertSentTo($user, DataReminderNotification::class, function (DataReminderNotification $n) {
                $project = $n->reminder['projects'][0];

                return $n->reminder['period_label'] === 'Oktober 2026'
                    && $n->reminder['round'] === 1
                    && $project['id'] === $this->project->id
                    && $project['empty'] === false
                    && $project['missing_factors'] === ['Listrik PLN Jawa-Bali'];
            });
        }
        Notification::assertNotSentTo([$viewer, $inactive, $this->admin], DataReminderNotification::class);
    }

    public function test_unsubmitted_entries_are_reminded_to_their_creator_only(): void
    {
        $this->draft($this->member);
        $this->draft($this->member, 'rejected');
        $this->draft($this->member, 'draft', '2026-09-15'); // bulan lain: tidak dihitung
        Notification::fake();

        $this->artisan('entries:send-data-reminders')->assertSuccessful();

        Notification::assertSentTo($this->member, DataReminderNotification::class,
            fn (DataReminderNotification $n) => $n->reminder['unsubmitted'] === [
                ['project_id' => $this->project->id, 'project_name' => $this->project->name, 'count' => 2],
            ]);
        Notification::assertSentTo($this->owner, DataReminderNotification::class,
            fn (DataReminderNotification $n) => $n->reminder['unsubmitted'] === []);
    }

    public function test_second_round_only_reminds_what_is_still_missing(): void
    {
        Notification::fake();
        $this->artisan('entries:send-data-reminders')->assertSuccessful();

        // Sebelum tanggal 10 listrik Oktober dicatat: tidak ada lagi yang kosong
        $this->makeApprovedEntry($this->project, $this->member, 510, '2026-10-20', 'electricity_jawa_bali');
        $this->travelTo(Carbon::parse('2026-11-10 08:00', 'Asia/Jakarta'));
        $this->artisan('entries:send-data-reminders --round=2')->assertSuccessful();

        Notification::assertSentToTimes($this->owner, DataReminderNotification::class, 1);
    }

    public function test_marked_no_data_items_are_not_reminded(): void
    {
        DataGapDismissal::create([
            'project_id' => $this->project->id,
            'emission_factor_id' => EmissionFactor::where('slug', 'electricity_jawa_bali')->value('id'),
            'period_year' => 2026,
            'period_month' => 10,
            'dismissed_by' => $this->owner->id,
        ]);
        Notification::fake();

        $this->artisan('entries:send-data-reminders')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_each_round_runs_once_per_month_unless_forced(): void
    {
        Notification::fake();

        $this->artisan('entries:send-data-reminders')->assertSuccessful();
        $this->artisan('entries:send-data-reminders')->assertSuccessful();
        Notification::assertSentToTimes($this->owner, DataReminderNotification::class, 1);

        $this->artisan('entries:send-data-reminders --force')->assertSuccessful();
        Notification::assertSentToTimes($this->owner, DataReminderNotification::class, 2);
    }

    public function test_dry_run_lists_recipients_without_sending(): void
    {
        Notification::fake();

        $this->artisan('entries:send-data-reminders --dry-run')
            ->expectsOutputToContain($this->owner->email)
            ->assertSuccessful();

        Notification::assertNothingSent();
        $this->artisan('entries:send-data-reminders')->assertSuccessful();
        Notification::assertSentTo($this->owner, DataReminderNotification::class);
    }

    public function test_email_follows_the_profile_preference(): void
    {
        $this->member->update(['email_notifications' => false]);
        Notification::fake();

        $this->artisan('entries:send-data-reminders')->assertSuccessful();

        Notification::assertSentTo($this->owner, DataReminderNotification::class,
            fn ($n, array $channels) => $channels === ['database', 'mail']);
        Notification::assertSentTo($this->member, DataReminderNotification::class,
            fn ($n, array $channels) => $channels === ['database']);
    }

    public function test_in_app_payload_links_to_the_project_or_dashboard(): void
    {
        $second = $this->makeProject($this->owner, 'PRJ-2'); // kosong di Oktober
        Notification::fake();

        $this->artisan('entries:send-data-reminders')->assertSuccessful();

        $forMember = Notification::sent($this->member, DataReminderNotification::class)->first()->toArray($this->member);
        $this->assertSame($this->project->id, $forMember['project_id']);
        $this->assertSame("/projects/{$this->project->id}", $forMember['link']);
        $this->assertStringContainsString('Listrik PLN Jawa-Bali', $forMember['message']);

        $forOwner = Notification::sent($this->owner, DataReminderNotification::class)->first()->toArray($this->owner);
        $this->assertNull($forOwner['project_id']);
        $this->assertSame('/', $forOwner['link']);
        $this->assertStringContainsString('2 project', $forOwner['message']);
        $this->assertNotNull($second);
    }

    public function test_email_lists_projects_and_escapes_names(): void
    {
        $this->project->update(['name' => 'Gedung *A* [2]']);
        $this->draft($this->owner);
        Notification::fake();
        $this->artisan('entries:send-data-reminders')->assertSuccessful();

        $mail = Notification::sent($this->owner, DataReminderNotification::class)->first()->toMail($this->owner);
        $html = (string) $mail->render();

        $this->assertSame('Data emisi Oktober 2026 belum lengkap', $mail->subject);
        $this->assertStringContainsString('Gedung *A* [2]', $html);
        $this->assertStringContainsString('Listrik PLN Jawa-Bali', $html);
        $this->assertStringContainsString('1 entri', $html);
        $this->assertStringContainsString('tidak ada data', $html);
    }

    public function test_reminders_are_scheduled_on_the_5th_and_10th_in_business_time(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($e) => str_contains((string) $e->command, 'entries:send-data-reminders'))
            ->values();

        $this->assertCount(2, $events);
        $this->assertSame(['0 8 5 * *', '0 8 10 * *'], $events->pluck('expression')->all());
        $this->assertSame('Asia/Jakarta', $events[0]->timezone);
        $this->assertStringContainsString('--round=2', (string) $events[1]->command);
    }
}
