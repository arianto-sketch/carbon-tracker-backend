<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Membuat akun admin pertama di server production. Seeder hanya untuk development karena
 * memakai password bawaan; di production password diketik (tidak tersimpan di riwayat shell).
 */
class CreateAdmin extends Command
{
    private const MIN_PASSWORD = 12;

    protected $signature = 'app:create-admin {email : Email login admin} {--name=Administrator : Nama yang ditampilkan}
        {--password-stdin : Baca password dari baris pertama STDIN (untuk script), tanpa konfirmasi}';

    protected $description = 'Buat akun admin aktif dengan password yang diketik';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->error("Email tidak valid: {$email}");

            return self::FAILURE;
        }

        if (User::where('email', $email)->exists()) {
            $this->error("Email {$email} sudah terdaftar. Ubah role atau password-nya lewat aplikasi.");

            return self::FAILURE;
        }

        if ($this->option('password-stdin')) {
            $password = $confirmation = $this->readPasswordFromStdin();
        } else {
            $password = (string) $this->secret('Password (min. '.self::MIN_PASSWORD.' karakter)');
            $confirmation = (string) $this->secret('Ulangi password');
        }

        if (mb_strlen($password) < self::MIN_PASSWORD) {
            $this->error('Password minimal '.self::MIN_PASSWORD.' karakter.');

            return self::FAILURE;
        }

        if (! hash_equals($password, $confirmation)) {
            $this->error('Password dan ulangannya tidak sama.');

            return self::FAILURE;
        }

        User::create([
            'name'      => trim((string) $this->option('name')) ?: 'Administrator',
            'email'     => $email,
            'password'  => $password,
            'role'      => 'admin',
            'is_active' => true,
        ]);

        $this->info("Admin {$email} dibuat. Login lewat aplikasi lalu tambahkan user lain di halaman Users.");

        return self::SUCCESS;
    }

    private function readPasswordFromStdin(): string
    {
        // Stream bisa diganti di test lewat container; default STDIN proses
        $stream = app()->bound('admin.password_stdin') ? app('admin.password_stdin') : STDIN;

        return rtrim((string) fgets($stream), "\r\n");
    }
}
