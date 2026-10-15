<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Admin pertama di server production dibuat lewat perintah ini (bukan seeder berpassword bawaan).
 */
class CreateAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Rahasia-Panjang-2026';

    public function test_creates_an_active_admin_with_the_typed_password(): void
    {
        $this->artisan('app:create-admin', ['email' => 'ops@example.test', '--name' => 'Tim Ops'])
            ->expectsQuestion('Password (min. 12 karakter)', self::PASSWORD)
            ->expectsQuestion('Ulangi password', self::PASSWORD)
            ->expectsOutputToContain('Admin ops@example.test dibuat')
            ->assertSuccessful();

        $admin = User::where('email', 'ops@example.test')->sole();
        $this->assertSame('Tim Ops', $admin->name);
        $this->assertSame('admin', $admin->role);
        $this->assertTrue($admin->is_active);
        $this->assertTrue(Hash::check(self::PASSWORD, $admin->password));
    }

    public function test_password_can_be_piped_for_scripts(): void
    {
        $stdin = fopen('php://memory', 'r+');
        fwrite($stdin, self::PASSWORD."\n");
        rewind($stdin);
        $this->app->instance('admin.password_stdin', $stdin);

        $this->artisan('app:create-admin', ['email' => 'ci@example.test', '--password-stdin' => true])
            ->assertSuccessful();

        $this->assertTrue(Hash::check(self::PASSWORD, User::where('email', 'ci@example.test')->sole()->password));
    }

    public function test_refuses_an_email_that_is_already_registered(): void
    {
        User::factory()->create(['email' => 'ops@example.test']);

        $this->artisan('app:create-admin', ['email' => 'ops@example.test', '--name' => 'Tim Ops'])
            ->expectsOutputToContain('sudah terdaftar')
            ->assertFailed();
    }

    public function test_refuses_an_invalid_email(): void
    {
        $this->artisan('app:create-admin', ['email' => 'bukan-email', '--name' => 'Tim Ops'])
            ->expectsOutputToContain('Email tidak valid')
            ->assertFailed();
    }

    public function test_refuses_a_short_or_mismatched_password(): void
    {
        $this->artisan('app:create-admin', ['email' => 'ops@example.test', '--name' => 'Tim Ops'])
            ->expectsQuestion('Password (min. 12 karakter)', 'pendek')
            ->expectsQuestion('Ulangi password', 'pendek')
            ->expectsOutputToContain('minimal 12 karakter')
            ->assertFailed();

        $this->artisan('app:create-admin', ['email' => 'ops@example.test', '--name' => 'Tim Ops'])
            ->expectsQuestion('Password (min. 12 karakter)', self::PASSWORD)
            ->expectsQuestion('Ulangi password', self::PASSWORD.'x')
            ->expectsOutputToContain('tidak sama')
            ->assertFailed();

        $this->assertSame(0, User::count());
    }
}
