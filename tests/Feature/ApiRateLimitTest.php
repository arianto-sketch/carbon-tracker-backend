<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_requests_are_limited_to_120_per_minute_per_user(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        for ($i = 0; $i < 120; $i++) {
            $this->actingAs($user, 'sanctum')->getJson('/api/v1/auth/me')->assertOk();
        }

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/auth/me')
            ->assertStatus(429)
            ->assertJsonPath('message', 'Terlalu banyak permintaan. Coba lagi sebentar lagi.');

        // Batas dihitung per user, bukan per IP
        $this->actingAs($other, 'sanctum')->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_limit_follows_api_rate_limit_configuration(): void
    {
        $user = User::factory()->create();

        config(['app.api_rate_limit' => 3]);
        foreach (range(1, 3) as $i) {
            $this->actingAs($user, 'sanctum')->getJson('/api/v1/auth/me')->assertOk();
        }
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/auth/me')->assertStatus(429);
    }
}
