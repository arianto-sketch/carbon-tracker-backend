<?php

namespace App\Imports;

use Maatwebsite\Excel\Concerns\WithHeadingRow;

/**
 * Pembacaan file import entri: baris pertama adalah heading
 * (tanggal, kode_faktor, jumlah, keterangan, vendor, tipe_aktivitas).
 */
class CarbonEntryImport implements WithHeadingRow
{
    public const COLUMNS = ['tanggal', 'kode_faktor', 'jumlah', 'keterangan', 'vendor', 'tipe_aktivitas'];
}
