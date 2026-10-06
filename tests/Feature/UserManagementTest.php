<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create(['name' => 'Admin Utama']);
    }

    public function test_non_admin_cannot_manage_users(): void
    {
        $pm = User::factory()->create();

        $this->actingAs($pm, 'sanctum')->getJson('/api/v1/users')->assertForbidden();
        $this->actingAs($pm, 'sanctum')->postJson('/api/v1/users', [])->assertForbidden();
        $this->actingAs($pm, 'sanctum')->putJson("/api/v1/users/{$pm->id}", ['role' => 'admin'])->assertForbidden();

        $this->assertSame('pm', $pm->fresh()->role);
    }

    public function test_admin_lists_users_with_search_role_filter_and_meta(): void
    {
        User::factory()->create(['name' => 'Budi Santoso', 'email' => 'budi@example.com']);
        User::factory()->viewer()->create(['name' => 'Citra Viewer']);
        User::factory()->count(20)->create();

        $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/users?search=budi')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'budi@example.com');

        $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/users?role=viewer')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Citra Viewer');

        $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/users?page=2')
            ->assertOk()
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.total', 23)
            ->assertJsonPath('meta.per_page', 15);
    }

    public function test_admin_creates_user(): void
    {
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/users', [
            'name' => 'Dewi',
            'email' => 'dewi@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'role' => 'viewer',
        ])->assertCreated()->assertJsonPath('data.role', 'viewer');

        $this->postJson('/api/v1/auth/login', ['email' => 'dewi@example.com', 'password' => 'Password123!'])->assertOk();
    }

    public function test_create_user_validates_unique_email_and_role(): void
    {
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/users', [
            'name' => 'X',
            'email' => $this->admin->email,
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'role' => 'superuser',
        ])->assertStatus(422)->assertJsonValidationErrors(['email', 'role']);
    }

    public function test_admin_updates_name_and_role(): void
    {
        $user = User::factory()->create();

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/users/{$user->id}", ['name' => 'Nama Baru', 'role' => 'viewer'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Nama Baru')
            ->assertJsonPath('data.role', 'viewer');
    }

    public function test_admin_cannot_deactivate_self(): void
    {
        User::factory()->admin()->create(); // admin lain ada, tetap tidak boleh menonaktifkan diri sendiri

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/users/{$this->admin->id}", ['is_active' => false])
            ->assertStatus(422)
            ->assertJsonValidationErrors('is_active');

        $this->assertTrue($this->admin->fresh()->is_active);
    }

    public function test_last_active_admin_cannot_be_demoted(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/users/{$this->admin->id}", ['role' => 'pm'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('role');

        $this->assertSame('admin', $this->admin->fresh()->role);
    }

    public function test_admin_can_demote_another_admin_when_one_remains(): void
    {
        $other = User::factory()->admin()->create();

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/users/{$other->id}", ['role' => 'pm'])
            ->assertOk();
    }

    public function test_deactivated_user_loses_tokens_immediately(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/v1/users/{$user->id}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertSame(0, $user->tokens()->count());

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }
}
