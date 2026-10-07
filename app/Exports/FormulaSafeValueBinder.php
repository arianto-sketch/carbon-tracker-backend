<?php

namespace App\Exports;

use Maatwebsite\Excel\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

/**
 * Value binder XLSX: teks berawalan "=" ditulis sebagai sel teks, bukan formula, sehingga tidak
 * dieksekusi saat file dibuka (formula injection). Angka dan tipe lain tetap memakai binder default.
 * Dipakai dengan concern WithCustomValueBinder.
 */
class FormulaSafeValueBinder extends DefaultValueBinder
{
    public function bindValue(Cell $cell, $value)
    {
        if (is_string($value) && str_starts_with($value, '=')) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }
}
