<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * Ukuran halaman dari query `per_page` (1-100), atau default endpoint bila tidak dikirim.
     */
    protected function perPage(Request $request, int $default): int
    {
        $validated = $request->validate(
            ['per_page' => ['nullable', 'integer', 'min:1', 'max:100']],
            ['per_page.*' => 'per_page harus angka 1 sampai 100.'],
        );

        return (int) ($validated['per_page'] ?? $default);
    }
}
