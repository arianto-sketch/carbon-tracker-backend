<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Aturan validasi tanpa pesan khusus memakai pesan bawaan berbahasa Indonesia, lengkap dengan nama field.
 */
class ValidationLocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_validation_messages_are_in_indonesian(): void
    {
        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson('/api/v1/dashboard/summary?period_year=abc&period_month=13')
            ->assertStatus(422)
            ->assertJsonPath('errors.period_year.0', 'Tahun harus berupa bilangan bulat.')
            ->assertJsonPath('errors.period_month.0', 'Bulan maksimal 12.');
    }
}
