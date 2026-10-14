<?php

namespace Tests\Feature;

use App\Models\CarbonEntry;
use App\Models\Project;
use App\Models\User;
use App\Notifications\EntryWorkflowNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsCarbonData;
use Tests\TestCase;

/**
 * Event approval yang sama dengan notifikasi in-app juga dikirim lewat email (antrean),
 * kecuali user mematikannya di Profil.
 */
class EmailNotificationTest extends TestCase
{
    use BuildsCarbonData, RefreshDatabase;

    private User $owner;

    private User $member;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMasterData();
        $this->owner = User::factory()->create(['name' => 'Owner Satu']);
        $this->member = User::factory()->create(['name' => 'Member Dua']);
        $this->project = $this->makeProject($this->owner);
        $this->addMember($this->project, $this->member, 'member');
    }

    private function act(User $user, CarbonEntry $entry, string $action, array $data = []): void
    {
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/projects/{$this->project->id}/entries/{$entry->id}/{$action}", $data)
            ->assertOk();
    }

    public function test_approval_events_are_emailed_as_well_as_shown_in_app(): void
    {
        Notification::fake();

        $this->act($this->member, $this->makeEntry($this->project, $this->member), 'submit');

        Notification::assertSentTo($this->owner, EntryWorkflowNotification::class,
            fn ($notification, array $channels) => $channels === ['database', 'mail']);
    }

    public function test_users_who_turned_email_off_only_get_in_app_notifications(): void
    {
        $this->owner->update(['email_notifications' => false]);
        Notification::fake();

        $this->act($this->member, $this->makeEntry($this->project, $this->member), 'submit');

        Notification::assertSentTo($this->owner, EntryWorkflowNotification::class,
            fn ($notification, array $channels) => $channels === ['database']);
    }

    public function test_inactive_users_are_not_emailed(): void
    {
        $entry = $this->makeEntry($this->project, $this->member, 'submitted');
        $this->member->update(['is_active' => false]);
        Notification::fake();

        $this->act($this->owner, $entry, 'approve');

        Notification::assertSentTo($this->member, EntryWorkflowNotification::class,
            fn ($notification, array $channels) => $channels === ['database']);
    }

    public function test_email_is_queued_while_in_app_notification_is_stored_immediately(): void
    {
        Queue::fake();

        $this->act($this->member, $this->makeEntry($this->project, $this->member), 'submit');

        Queue::assertPushed(SendQueuedNotifications::class,
            fn (SendQueuedNotifications $job) => $job->channels === ['mail'] && $job->connection === null);
        Queue::assertPushed(SendQueuedNotifications::class,
            fn (SendQueuedNotifications $job) => $job->channels === ['database'] && $job->connection === 'sync');
    }

    public function test_rejection_email_states_the_reason_and_links_to_the_project(): void
    {
        config(['app.frontend_url' => 'https://carbon.example.test']);
        $entry = $this->makeEntry($this->project, $this->member, 'submitted');

        $mail = (new EntryWorkflowNotification('entry_rejected', $entry, $this->owner, 'Struk tidak terbaca.'))->toMail($this->member);

        $this->assertSame('Entri ditolak: Project PRJ-1', $mail->subject);
        $this->assertSame('Halo Member Dua,', $mail->greeting);
        $this->assertContains('Owner Satu menolak entri Anda di project Project PRJ-1.', $mail->introLines);
        $this->assertContains('Alasan: Struk tidak terbaca.', $mail->introLines);
        $this->assertSame("https://carbon.example.test/projects/{$this->project->id}", $mail->actionUrl);

        // Teks bawaan template email Laravel ikut berbahasa Indonesia
        $html = (string) $mail->render();
        $this->assertStringNotContainsString("If you're having trouble", $html);
        $this->assertStringContainsString('salin dan tempel URL berikut', $html);
    }

    public function test_reviewer_text_cannot_inject_links_into_the_email(): void
    {
        $entry = $this->makeEntry($this->project, $this->member, 'submitted');

        $html = (string) (new EntryWorkflowNotification('entry_rejected', $entry, $this->owner, 'Lihat [panduan](https://evil.test) **segera**'))
            ->toMail($this->member)->render();

        $this->assertStringNotContainsString('href="https://evil.test"', $html);
        $this->assertStringNotContainsString('<strong>segera</strong>', $html);
        $this->assertStringContainsString('[panduan](https://evil.test)', $html);
    }

    public function test_queued_email_is_dropped_when_the_entry_is_gone_and_waits_for_commit(): void
    {
        $notification = new EntryWorkflowNotification('entry_approved', $this->makeEntry($this->project, $this->member), $this->owner);

        $this->assertTrue($notification->deleteWhenMissingModels);
        $this->assertTrue($notification->afterCommit);
        $this->assertGreaterThan(1, $notification->tries);
    }

    public function test_user_can_turn_email_notifications_off_from_profile(): void
    {
        $this->actingAs($this->member, 'sanctum')->getJson('/api/v1/auth/me')
            ->assertJsonPath('data.email_notifications', true);

        $this->actingAs($this->member, 'sanctum')->putJson('/api/v1/auth/me', ['name' => 'Member Dua', 'email_notifications' => false])
            ->assertOk()
            ->assertJsonPath('data.email_notifications', false);

        $this->assertFalse($this->member->fresh()->email_notifications);
    }

    public function test_email_preference_is_only_visible_to_the_user_themselves(): void
    {
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/users?search=Member')
            ->assertOk()
            ->assertJsonMissingPath('data.0.email_notifications');
    }
}
