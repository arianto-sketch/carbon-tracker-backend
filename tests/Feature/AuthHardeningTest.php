<?php

namespace Tests\Feature;

use App\Models\EmissionFactor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCarbonData;
use Tests\TestCase;

class AuthHardeningTest extends TestCase
{
    use BuildsCarbonData, RefreshDatabase;

    public function test_non_admin_cannot_deactivate_emission_factor(): void
    {
        $this->seedMasterData();
        $factor = $this->gasolineFactor();

        foreach ([User::factory()->create(), User::factory()->viewer()->create()] as $user) {
            $this->actingAs($user, 'sanctum')
                ->deleteJson("/api/v1/emission-factors/{$factor->id}")
                ->assertForbidden();
        }

        $this->assertTrue(EmissionFactor::find($factor->id)->is_active);
    }

    public function test_admin_can_deactivate_emission_factor(): void
    {
        $this->seedMasterData();
        $factor = $this->gasolineFactor();

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/v1/emission-factors/{$factor->id}")
            ->assertOk();

        $this->assertFalse(EmissionFactor::find($factor->id)->is_active);
    }

    public function test_login_is_rate_limited_per_email(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong'])
                ->assertStatus(422);
        }

        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertStatus(429);
    }

    public function test_unauthenticated_browser_request_gets_401_not_500(): void
    {
        // A plain browser navigation (no "Accept: application/json"), e.g. an <a href> download link.
        $this->get('/api/v1/reports/download/1')->assertUnauthorized();
    }

    public function test_tokens_expire(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk();

        $this->travel(config('sanctum.expiration') + 1)->minutes();
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_deactivated_user_loses_access_with_existing_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('api')->plainTextToken;

        $user->update(['is_active' => false]);

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->assertSame(0, $user->tokens()->count());
    }
}
