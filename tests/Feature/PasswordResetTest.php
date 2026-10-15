<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/**
 * Lupa password: link reset lewat email (berlaku 60 menit), juga bisa dikirim admin.
 */
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private const GENERIC = 'Jika email terdaftar dan aktif, link reset password sudah dikirim.';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.frontend_url' => 'https://carbon.example.test']);
        $this->user = User::factory()->create(['email' => 'budi@example.test', 'name' => 'Budi']);
    }

    public function test_forgot_password_emails_a_link_to_the_spa(): void
    {
        Notification::fake();

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'BUDI@example.test'])
            ->assertOk()
            ->assertJsonPath('message', self::GENERIC);

        Notification::assertSentTo($this->user, ResetPasswordNotification::class, function (ResetPasswordNotification $n) {
            $mail = $n->toMail($this->user);

            // Token di fragment (#): tidak terkirim ke server, jadi tidak tercatat di access log nginx
            return str_starts_with($mail->actionUrl, 'https://carbon.example.test/reset-password#token=')
                && str_contains($mail->actionUrl, 'email=budi%40example.test')
                && $mail->subject === 'Reset password Carbon Tracker';
        });
    }

    public function test_reset_email_is_queued(): void
    {
        $this->assertInstanceOf(ShouldQueue::class, new ResetPasswordNotification('token'));
    }

    public function test_unknown_or_inactive_emails_get_the_same_answer_without_mail(): void
    {
        Notification::fake();
        User::factory()->create(['email' => 'nonaktif@example.test', 'is_active' => false]);

        foreach (['tidak-ada@example.test', 'nonaktif@example.test'] as $email) {
            $this->postJson('/api/v1/auth/forgot-password', ['email' => $email])
                ->assertOk()
                ->assertJsonPath('message', self::GENERIC);
        }

        Notification::assertNothingSent();
    }

    public function test_reset_changes_password_revokes_sessions_and_token_works_once(): void
    {
        $this->user->createToken('laptop');
        $token = Password::createToken($this->user);
        $payload = ['token' => $token, 'email' => 'budi@example.test', 'password' => 'baru-sekali-123', 'password_confirmation' => 'baru-sekali-123'];

        $this->postJson('/api/v1/auth/reset-password', $payload)
            ->assertOk()
            ->assertJsonPath('message', 'Password berhasil direset. Silakan login dengan password baru.');

        $this->assertTrue(Hash::check('baru-sekali-123', $this->user->fresh()->password));
        $this->assertSame(0, $this->user->tokens()->count());
        $this->assertTrue(AuditLog::where('action', 'password_reset')->where('model_id', $this->user->id)->where('user_id', $this->user->id)->exists());
        $this->postJson('/api/v1/auth/login', ['email' => 'budi@example.test', 'password' => 'baru-sekali-123'])->assertOk();

        $this->postJson('/api/v1/auth/reset-password', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_invalid_token_or_weak_password_is_rejected(): void
    {
        $this->postJson('/api/v1/auth/reset-password', [
            'token' => 'palsu', 'email' => 'budi@example.test', 'password' => 'baru-sekali-123', 'password_confirmation' => 'baru-sekali-123',
        ])->assertUnprocessable()->assertJsonPath('errors.email.0', 'Link reset password tidak valid atau sudah kedaluwarsa.');

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => Password::createToken($this->user), 'email' => 'budi@example.test', 'password' => 'pendek', 'password_confirmation' => 'pendek',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    public function test_forgot_password_is_rate_limited_per_ip(): void
    {
        Notification::fake();

        foreach (range(1, 5) as $i) {
            $this->postJson('/api/v1/auth/forgot-password', ['email' => "x{$i}@example.test"])->assertOk();
        }

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'x6@example.test'])->assertStatus(429);
    }

    public function test_one_mailbox_cannot_be_flooded_with_reset_emails(): void
    {
        Notification::fake();

        foreach (range(1, 3) as $i) {
            $this->withServerVariables(['REMOTE_ADDR' => "10.0.0.{$i}"])
                ->postJson('/api/v1/auth/forgot-password', ['email' => 'budi@example.test'])->assertOk();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.9'])
            ->postJson('/api/v1/auth/forgot-password', ['email' => 'BUDI@example.test'])->assertStatus(429);
    }

    public function test_reset_links_stop_working_when_the_account_is_deactivated(): void
    {
        $admin = User::factory()->admin()->create();
        $token = Password::createToken($this->user);

        $this->actingAs($admin, 'sanctum')->putJson("/api/v1/users/{$this->user->id}", ['is_active' => false])->assertOk();

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token, 'email' => 'budi@example.test', 'password' => 'baru-sekali-123', 'password_confirmation' => 'baru-sekali-123',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertFalse(Hash::check('baru-sekali-123', $this->user->fresh()->password));
    }

    public function test_inactive_user_cannot_reset_even_with_a_valid_token(): void
    {
        $token = Password::createToken($this->user);
        $this->user->forceFill(['is_active' => false])->save();

        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token, 'email' => 'budi@example.test', 'password' => 'baru-sekali-123', 'password_confirmation' => 'baru-sekali-123',
        ])->assertUnprocessable()->assertJsonPath('errors.email.0', 'Link reset password tidak valid atau sudah kedaluwarsa.');
    }

    public function test_changing_password_invalidates_pending_reset_links(): void
    {
        $token = Password::createToken($this->user);
        $this->user->forceFill(['password' => 'lama-sekali-1'])->save();

        $this->actingAs($this->user, 'sanctum')->putJson('/api/v1/auth/me/password', [
            'current_password' => 'lama-sekali-1', 'password' => 'baru-sekali-1', 'password_confirmation' => 'baru-sekali-1',
        ])->assertOk();

        $this->assertFalse(Password::tokenExists($this->user, $token));
    }

    public function test_queued_reset_email_is_dropped_if_the_user_was_deactivated_meanwhile(): void
    {
        $this->user->forceFill(['is_active' => false])->save();

        $this->assertFalse((new ResetPasswordNotification('token'))->shouldSend($this->user, 'mail'));
    }

    public function test_admin_can_send_a_reset_link_to_a_user(): void
    {
        Notification::fake();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/users/{$this->user->id}/password-reset-link")
            ->assertOk()
            ->assertJsonPath('message', 'Link reset password dikirim ke budi@example.test.');

        Notification::assertSentTo($this->user, ResetPasswordNotification::class);
        $this->assertTrue(AuditLog::where('action', 'password_reset_link_sent')->where('model_id', $this->user->id)->where('user_id', $admin->id)->exists());
    }

    public function test_reset_link_by_admin_is_refused_for_inactive_users_and_non_admins(): void
    {
        Notification::fake();
        $admin = User::factory()->admin()->create();
        $inactive = User::factory()->create(['is_active' => false]);

        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/users/{$inactive->id}/password-reset-link")->assertUnprocessable();
        $this->actingAs($this->user, 'sanctum')->postJson("/api/v1/users/{$inactive->id}/password-reset-link")->assertForbidden();

        Notification::assertNothingSent();
    }
}
