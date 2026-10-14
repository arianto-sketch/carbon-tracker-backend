<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Perubahan nama, role, dan status aktif user dicatat di audit log, dan bisa dilihat admin.
 */
class UserAuditTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create(['name' => 'Admin Utama']);
    }

    public function test_role_and_status_changes_are_logged_with_actor(): void
    {
        $user = User::factory()->create(['role' => 'pm']);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/users/{$user->id}", ['role' => 'viewer', 'is_active' => false])
            ->assertOk();

        $log = AuditLog::where('model_type', User::class)->where('model_id', $user->id)->where('action', 'updated')->sole();
        $this->assertSame($this->admin->id, $log->user_id);
        $this->assertSame(['role' => 'pm', 'is_active' => true], $log->old_values);
        $this->assertSame(['role' => 'viewer', 'is_active' => false], $log->new_values);
    }

    public function test_created_user_is_logged_without_sensitive_fields(): void
    {
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/users', [
            'name' => 'Budi', 'email' => 'budi@example.test', 'password' => 'rahasia123', 'password_confirmation' => 'rahasia123', 'role' => 'pm',
        ])->assertCreated();

        $budi = User::where('email', 'budi@example.test')->sole();
        $log = AuditLog::where('model_type', User::class)->where('model_id', $budi->id)->where('action', 'created')->sole();
        $this->assertSame($this->admin->id, $log->user_id);
        $this->assertSame(['name' => 'Budi', 'role' => 'pm', 'is_active' => true], $log->new_values);
    }

    public function test_password_change_is_not_logged(): void
    {
        $user = User::factory()->create(['password' => 'lama-sekali-1']);

        $this->actingAs($user, 'sanctum')->putJson('/api/v1/auth/me/password', [
            'current_password' => 'lama-sekali-1', 'password' => 'baru-sekali-1', 'password_confirmation' => 'baru-sekali-1',
        ])->assertOk();

        $this->assertSame(0, AuditLog::where('model_type', User::class)->where('model_id', $user->id)->where('action', 'updated')->count());
    }

    public function test_admin_sees_user_history_with_actor_names(): void
    {
        $user = User::factory()->create(['role' => 'pm']);
        $this->actingAs($this->admin, 'sanctum')->putJson("/api/v1/users/{$user->id}", ['role' => 'viewer'])->assertOk();

        $this->actingAs($this->admin, 'sanctum')->getJson("/api/v1/users/{$user->id}/history")
            ->assertOk()
            ->assertJsonPath('data.0.action', 'updated')
            ->assertJsonPath('data.0.user.name', 'Admin Utama')
            ->assertJsonPath('data.0.changes.role.old', 'pm')
            ->assertJsonPath('data.0.changes.role.new', 'viewer');
    }

    public function test_non_admin_cannot_see_user_history(): void
    {
        $pm = User::factory()->create(['role' => 'pm']);

        $this->actingAs($pm, 'sanctum')->getJson("/api/v1/users/{$pm->id}/history")->assertForbidden();
    }
}
