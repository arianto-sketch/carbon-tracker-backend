<?php

namespace App\Exports;

use App\Models\CarbonEntry;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class CarbonReportExport implements FromQuery, WithHeadings, WithMapping, WithTitle, ShouldAutoSize, WithStyles
{
    public function __construct(private array $filters) {}

    public function query()
    {
        $query = CarbonEntry::with(['project', 'category', 'emissionFactor', 'createdBy'])
            ->where('status', 'approved')
            ->orderBy('entry_date');

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
            $entry->project?->name,
            $entry->category?->name,
            $entry->emissionFactor?->name,
            $entry->entry_date?->format('d/m/Y'),
            $entry->period_month . '/' . $entry->period_year,
            $entry->quantity,
            $entry->source_unit,
            $entry->emission_factor_value,
            $entry->co2e_kg,
            $entry->description,
            $entry->vendor_name,
            $entry->activity_type,
            $entry->createdBy?->name,
        ];
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
