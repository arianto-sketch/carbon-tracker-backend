<?php

namespace App\Exports;

use App\Imports\CarbonEntryImport;
use App\Models\EmissionFactor;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Template import entri: sheet 1 untuk diisi, sheet 2 daftar kode faktor emisi aktif.
 */
class CarbonEntryImportTemplate implements WithMultipleSheets
{
    public function sheets(): array
    {
        return [
            new class extends FormulaSafeValueBinder implements FromArray, WithTitle, ShouldAutoSize, WithCustomValueBinder
            {
                public function array(): array
                {
                    return [CarbonEntryImport::COLUMNS];
                }

                public function title(): string
                {
                    return 'Entri';
                }
            },
            // Nama faktor/kategori/satuan diisi admin: tetap dinetralkan agar tidak jadi formula
            new class extends FormulaSafeValueBinder implements FromArray, WithTitle, ShouldAutoSize, WithCustomValueBinder
            {
                public function array(): array
                {
                    $factors = EmissionFactor::with('category')->where('is_active', true)
                        ->orderBy('category_id')->orderBy('name')->get();

                    return [
                        ['kode_faktor', 'nama', 'kategori', 'satuan', 'faktor (kg CO2e/satuan)'],
                        ...$factors->map(fn (EmissionFactor $f) => [
                            $f->slug, $f->name, $f->category?->name, $f->source_unit, (float) $f->factor_value,
                        ])->all(),
                    ];
                }

                public function title(): string
                {
                    return 'Kode Faktor';
                }
            },
        ];
    }
}
