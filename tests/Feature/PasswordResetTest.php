<?php

namespace Tests\Feature;

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

            return str_starts_with($mail->actionUrl, 'https://carbon.example.test/reset-password?token=')
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

    public function test_forgot_password_is_rate_limited(): void
    {
        Notification::fake();

        foreach (range(1, 5) as $_) {
            $this->postJson('/api/v1/auth/forgot-password', ['email' => 'x@example.test'])->assertOk();
        }

        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'x@example.test'])->assertStatus(429);
    }

    public function test_admin_can_send_a_reset_link_to_a_user(): void
    {
        Notification::fake();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')->postJson("/api/v1/users/{$this->user->id}/password-reset-link")
            ->assertOk()
            ->assertJsonPath('message', 'Link reset password dikirim ke budi@example.test.');

        Notification::assertSentTo($this->user, ResetPasswordNotification::class);
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
