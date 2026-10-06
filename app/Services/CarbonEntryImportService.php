<?php

namespace App\Services;

use App\Imports\CarbonEntryImport;
use App\Models\EmissionFactor;
use App\Models\Project;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

class CarbonEntryImportService
{
    public const MAX_ROWS = 500;

    public function __construct(private CarbonEntryService $entryService) {}

    /**
     * Baca file lalu validasi tiap baris tanpa menyimpan apa pun.
     * Nomor `row` mengikuti nomor baris di spreadsheet (heading = baris 1).
     */
    public function preview(UploadedFile $file): array
    {
        $rows = collect(Excel::toArray(new CarbonEntryImport, $file)[0] ?? [])
            ->map(fn ($row, $index) => ['row' => $index + 2, 'raw' => $row])
            ->reject(fn ($r) => collect($r['raw'])->every(fn ($v) => $v === null || trim((string) $v) === ''))
            ->values();

        if ($rows->count() > self::MAX_ROWS) {
            throw ValidationException::withMessages([
                'file' => ['File berisi '.$rows->count().' baris; maksimal '.self::MAX_ROWS.' baris per import.'],
            ]);
        }

        $factors = EmissionFactor::where('is_active', true)->get()->keyBy('slug');
        $today = now(config('app.business_timezone'))->toDateString();

        $result = $rows->map(fn ($r) => $this->previewRow($r['row'], $r['raw'], $factors, $today));

        return [
            'rows'          => $result->all(),
            'valid_count'   => $result->where('errors', [])->count(),
            'invalid_count' => $result->reject(fn ($r) => $r['errors'] === [])->count(),
        ];
    }

    /**
     * Simpan semua baris sebagai draft dalam satu transaksi (gagal satu, batal semua).
     */
    public function commit(array $rows, Project $project, User $user): int
    {
        return DB::transaction(function () use ($rows, $project, $user) {
            foreach ($rows as $row) {
                $this->entryService->create($row, $project, $user);
            }

            return count($rows);
        });
    }

    private function previewRow(int $rowNumber, array $raw, $factors, string $today): array
    {
        $values = [
            'tanggal'        => $this->normalizeDate($raw['tanggal'] ?? null),
            'kode_faktor'    => strtolower(trim((string) ($raw['kode_faktor'] ?? ''))),
            'jumlah'         => $raw['jumlah'] ?? null,
            'keterangan'     => $this->nullableString($raw['keterangan'] ?? null),
            'vendor'         => $this->nullableString($raw['vendor'] ?? null),
            'tipe_aktivitas' => $this->nullableString($raw['tipe_aktivitas'] ?? null),
        ];

        $validator = Validator::make($values, [
            'tanggal'        => ['required', 'date', 'before_or_equal:'.$today],
            'kode_faktor'    => ['required', Rule::in($factors->keys()->all())],
            'jumlah'         => ['required', 'numeric', 'gt:0'],
            'keterangan'     => ['nullable', 'string', 'max:1000'],
            'vendor'         => ['nullable', 'string', 'max:255'],
            'tipe_aktivitas' => ['nullable', 'string', 'max:255'],
        ], [
            'tanggal.required'        => 'Tanggal wajib diisi.',
            'tanggal.date'            => 'Format tanggal tidak dikenali (gunakan YYYY-MM-DD).',
            'tanggal.before_or_equal' => 'Tanggal tidak boleh di masa depan.',
            'kode_faktor.required'    => 'Kode faktor wajib diisi.',
            'kode_faktor.in'          => 'Kode faktor tidak dikenal atau tidak aktif (lihat sheet Kode Faktor).',
            'jumlah.required'         => 'Jumlah wajib diisi.',
            'jumlah.numeric'          => 'Jumlah harus berupa angka.',
            'jumlah.gt'               => 'Jumlah harus lebih dari 0.',
            '*.max'                   => 'Teks terlalu panjang.',
        ]);

        $factor = $factors->get($values['kode_faktor']);
        $errors = $validator->errors()->all();

        return [
            'row'    => $rowNumber,
            'values' => [
                'entry_date'         => $values['tanggal'],
                'emission_factor_id' => $factor?->id,
                'factor_name'        => $factor?->name,
                'source_unit'        => $factor?->source_unit,
                'quantity'           => is_numeric($values['jumlah']) ? (float) $values['jumlah'] : $values['jumlah'],
                'description'        => $values['keterangan'],
                'vendor_name'        => $values['vendor'],
                'activity_type'      => $values['tipe_aktivitas'],
                'co2e_kg'            => $errors === [] ? round((float) $values['jumlah'] * (float) $factor->factor_value, 4) : null,
            ],
            'errors' => $errors,
        ];
    }

    /** Tanggal dari Excel bisa berupa serial number (sel bertipe date) atau teks. */
    private function normalizeDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
        }

        return trim((string) $value);
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
