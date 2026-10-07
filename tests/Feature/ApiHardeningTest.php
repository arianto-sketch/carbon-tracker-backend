<?php

namespace Tests\Feature;

use App\Models\EmissionCategory;
use App\Models\EmissionFactor;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ApiHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_responses_send_nosniff_header(): void
    {
        $this->getJson('/api/v1/auth/me')->assertUnauthorized()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->getJson('/api/v1/tidak-ada')->assertNotFound()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs(User::factory()->create(), 'sanctum')->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_non_numeric_ids_return_404_instead_of_500(): void
    {
        $admin = User::factory()->admin()->create();
        $requests = [
            ['putJson', '/api/v1/users/abc'],
            ['getJson', '/api/v1/projects/abc'],
            ['getJson', '/api/v1/projects/abc/members'],
            ['putJson', '/api/v1/projects/1/members/abc'],
            ['getJson', '/api/v1/projects/abc/entries'],
            ['getJson', '/api/v1/projects/1/entries/abc'],
            ['getJson', '/api/v1/projects/1/targets/abc'],
            ['getJson', '/api/v1/emission-factors/abc'],
            ['getJson', '/api/v1/reports/jobs/abc'],
            // Angka di luar jangkauan int juga TypeError di controller
            ['getJson', '/api/v1/projects/99999999999999999999'],
        ];

        foreach ($requests as [$method, $url]) {
            $this->actingAs($admin, 'sanctum')->{$method}($url)->assertNotFound();
        }
    }

    public function test_notification_ids_are_still_accepted(): void
    {
        // Id notifikasi berupa UUID, jadi tidak boleh ikut dibatasi ke angka
        $user = User::factory()->create();
        $id = (string) Str::uuid();
        $user->notifications()->create(['id' => $id, 'type' => 'entry_approved', 'data' => ['type' => 'entry_approved']]);

        $this->actingAs($user, 'sanctum')->postJson("/api/v1/notifications/{$id}/read")
            ->assertOk()
            ->assertJsonPath('data.id', $id);

        $this->assertNotNull($user->notifications()->first()->read_at);
    }

    public function test_search_treats_percent_and_underscore_literally(): void
    {
        $admin = User::factory()->admin()->create();
        foreach (['Diskon 100%' => 'D-1', 'Diskon 1000' => 'D-2', 'Kode_A' => 'K-1', 'KodeXA' => 'K-2', 'Hore!' => 'H-1', 'Horee' => 'H-2'] as $name => $code) {
            Project::create(['name' => $name, 'code' => $code, 'start_date' => '2026-01-01', 'created_by' => $admin->id]);
        }
        User::factory()->create(['name' => 'Budi_Santoso']);
        User::factory()->create(['name' => 'BudiXSantoso']);
        $category = EmissionCategory::create(['name' => 'Energi', 'slug' => 'energy']);
        foreach (['Daur ulang 50%' => 'recycle_50', 'Daur ulang 500' => 'recycle_500'] as $name => $slug) {
            EmissionFactor::create([
                'category_id' => $category->id, 'name' => $name, 'slug' => $slug,
                'source_unit' => 'kg', 'factor_value' => 1, 'created_by' => $admin->id,
            ]);
        }

        $names = fn (string $url) => collect($this->actingAs($admin, 'sanctum')->getJson($url)->assertOk()->json('data'))->pluck('name')->all();

        $this->assertSame(['Diskon 100%'], $names('/api/v1/projects?search='.urlencode('100%')));
        $this->assertSame(['Kode_A'], $names('/api/v1/projects?search=Kode_'));
        // "!" adalah karakter ESCAPE, jadi harus ikut di-escape
        $this->assertSame(['Hore!'], $names('/api/v1/projects?search='.urlencode('!')));
        $this->assertSame(['Budi_Santoso'], $names('/api/v1/users?search=Budi_'));
        $this->assertSame(['Daur ulang 50%'], $names('/api/v1/emission-factors?search='.urlencode('50%')));
    }
}
