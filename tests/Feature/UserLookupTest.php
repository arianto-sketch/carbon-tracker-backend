<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Owner project (PM/admin) mencari user untuk ditambahkan sebagai anggota, tanpa akses ke daftar user admin.
 */
class UserLookupTest extends TestCase
{
    use RefreshDatabase;

    public function test_pm_finds_active_users_by_name_or_email_with_minimal_fields(): void
    {
        $pm = User::factory()->create(['role' => 'pm']);
        User::factory()->create(['name' => 'Budi Santoso', 'email' => 'budi@example.test', 'role' => 'viewer']);
        User::factory()->create(['name' => 'Sari', 'email' => 'budiarti@example.test']);
        User::factory()->create(['name' => 'Budi Nonaktif', 'email' => 'lama@example.test', 'is_active' => false]);

        $response = $this->actingAs($pm, 'sanctum')->getJson('/api/v1/users/lookup?search=budi')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->assertSame(['id', 'name', 'email', 'role'], array_keys($response->json('data.0')));
        $this->assertEqualsCanonicalizing(['budi@example.test', 'budiarti@example.test'], array_column($response->json('data'), 'email'));
    }

    public function test_results_are_limited_and_search_needs_two_characters(): void
    {
        $pm = User::factory()->create(['role' => 'pm']);
        User::factory()->count(12)->sequence(fn ($s) => ['name' => "Tim Proyek {$s->index}"])->create();

        $this->actingAs($pm, 'sanctum')->getJson('/api/v1/users/lookup?search=tim')->assertOk()->assertJsonCount(10, 'data');
        $this->actingAs($pm, 'sanctum')->getJson('/api/v1/users/lookup?search=t')->assertUnprocessable()->assertJsonValidationErrors('search');
    }

    public function test_viewers_cannot_look_up_users(): void
    {
        $viewer = User::factory()->create(['role' => 'viewer']);

        $this->actingAs($viewer, 'sanctum')->getJson('/api/v1/users/lookup?search=budi')->assertForbidden();
    }
}
