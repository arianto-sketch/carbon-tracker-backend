<?php

namespace App\Exports;

use App\Models\CarbonEntry;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class CarbonReportExport extends DefaultValueBinder implements FromQuery, WithHeadings, WithMapping, WithTitle, ShouldAutoSize, WithStyles, WithCustomValueBinder
{
    /** Karakter awal yang membuat teks dibaca sebagai formula oleh Excel/LibreOffice (OWASP). */
    private const FORMULA_TRIGGERS = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * @param  array<int>|null  $allowedProjectIds  null = semua project (admin)
     * @param  string  $format  xlsx / csv — menentukan cara menetralkan teks berawalan formula
     */
    public function __construct(
        private array $filters,
        private ?array $allowedProjectIds = null,
        private string $format = 'xlsx',
    ) {}

    public function query()
    {
        $query = CarbonEntry::with(['project', 'category', 'emissionFactor', 'createdBy'])
            ->where('status', 'approved')
            ->orderBy('entry_date');

        if ($this->allowedProjectIds !== null) {
            $query->whereIn('project_id', $this->allowedProjectIds);
        }

        if (! empty($this->filters['project_ids'])) {
            $query->whereIn('project_id', $this->filters['project_ids']);
        }

        if (! empty($this->filters['period_year'])) {
            $query->where('period_year', $this->filters['period_year']);
        }

        if (! empty($this->filters['period_month_from'])) {
            $query->where('period_month', '>=', $this->filters['period_month_from']);
        }

        if (! empty($this->filters['period_month_to'])) {
            $query->where('period_month', '<=', $this->filters['period_month_to']);
        }

        if (! empty($this->filters['category_ids'])) {
            $query->whereIn('category_id', $this->filters['category_ids']);
        }

        return $query;
    }

    public function headings(): array
    {
        return [
            'ID',
            'Project',
            'Kategori',
            'Faktor Emisi',
            'Tanggal',
            'Periode',
            'Jumlah',
            'Satuan',
            'Faktor (kg CO2e/unit)',
            'Emisi (kg CO2e)',
            'Keterangan',
            'Vendor / Supplier',
            'Tipe Aktivitas',
            'Di-input oleh',
        ];
    }

    public function map($entry): array
    {
        return [
            $entry->id,
            $this->text($entry->project?->name),
            $this->text($entry->category?->name),
            $this->text($entry->emissionFactor?->name),
            $entry->entry_date?->format('d/m/Y'),
            $entry->period_month . '/' . $entry->period_year,
            $entry->quantity,
            $this->text($entry->source_unit),
            $entry->emission_factor_value,
            $entry->co2e_kg,
            $this->text($entry->description),
            $this->text($entry->vendor_name),
            $this->text($entry->activity_type),
            $this->text($entry->createdBy?->name),
        ];
    }

    /**
     * CSV tidak punya tipe sel, jadi teks yang diawali karakter formula diberi awalan '.
     * XLSX dibiarkan utuh; pengamanannya lewat bindValue().
     */
    private function text(?string $value): ?string
    {
        if ($this->format === 'csv' && $value !== null && $value !== '' && in_array($value[0], self::FORMULA_TRIGGERS, true)) {
            return "'".$value;
        }

        return $value;
    }

    /** XLSX: teks berawalan "=" ditulis sebagai sel teks, bukan formula. Angka tetap numerik. */
    public function bindValue(Cell $cell, $value)
    {
        if (is_string($value) && str_starts_with($value, '=')) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }

    public function title(): string
    {
        return 'Carbon Entries';
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
