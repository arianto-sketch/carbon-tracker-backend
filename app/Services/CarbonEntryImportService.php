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

    public const MAX_QUANTITY = 1000000000;

    /** Batas sebelum parsing: PhpSpreadsheet memuat seluruh file ke memori (WithLimit tidak membatasi load). */
    private const MAX_XLSX_UNCOMPRESSED_BYTES = 5 * 1024 * 1024;

    private const MAX_CSV_LINES = 1001; // heading + 1000 baris mentah (termasuk baris kosong)

    private const MAX_COLUMNS = 20;

    /** Rentang serial tanggal Excel yang valid (1900-01-01 s/d 9999-12-31). */
    private const EXCEL_SERIAL_MIN = 1;

    private const EXCEL_SERIAL_MAX = 2958465;

    public function __construct(private CarbonEntryService $entryService) {}

    /**
     * Baca file lalu validasi tiap baris tanpa menyimpan apa pun.
     * Nomor `row` mengikuti nomor baris di spreadsheet (heading = baris 1).
     */
    public function preview(UploadedFile $file): array
    {
        $this->assertSafeToParse($file);

        try {
            $sheet = Excel::toArray(new CarbonEntryImport, $file)[0] ?? [];
        } catch (\Throwable $e) {
            report($e);
            $this->rejectFile('File tidak bisa dibaca. Pastikan file .xlsx/.csv valid (gunakan template).');
        }

        $rows = collect($sheet)
            ->map(fn ($row, $index) => ['row' => $index + 2, 'raw' => $row])
            ->reject(fn ($r) => collect($r['raw'])->every(fn ($v) => $v === null || trim((string) $v) === ''))
            ->values();

        if ($rows->count() > self::MAX_ROWS) {
            $this->rejectFile('File berisi '.$rows->count().' baris; maksimal '.self::MAX_ROWS.' baris per import.');
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
            'tanggal'        => ['required', 'date_format:Y-m-d', 'before_or_equal:'.$today],
            'kode_faktor'    => ['required', Rule::in($factors->keys()->all())],
            'jumlah'         => ['required', 'numeric', 'gt:0', 'max:'.self::MAX_QUANTITY],
            'keterangan'     => ['nullable', 'string', 'max:1000'],
            'vendor'         => ['nullable', 'string', 'max:255'],
            'tipe_aktivitas' => ['nullable', 'string', 'max:255'],
        ], [
            'tanggal.required'        => 'Tanggal wajib diisi.',
            'tanggal.date_format'     => 'Format tanggal harus YYYY-MM-DD (mis. 2026-03-05).',
            'tanggal.before_or_equal' => 'Tanggal tidak boleh di masa depan.',
            'kode_faktor.required'    => 'Kode faktor wajib diisi.',
            'kode_faktor.in'          => 'Kode faktor tidak dikenal atau tidak aktif (lihat sheet Kode Faktor).',
            'jumlah.required'         => 'Jumlah wajib diisi.',
            'jumlah.numeric'          => 'Jumlah harus berupa angka.',
            'jumlah.gt'               => 'Jumlah harus lebih dari 0.',
            'jumlah.max'              => 'Jumlah maksimal 1.000.000.000.',
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
                // Angka ekstrem (mis. 1e400 = INF) dikembalikan sebagai teks agar respons JSON tetap valid
                'quantity'           => is_numeric($values['jumlah']) && is_finite((float) $values['jumlah'])
                    ? (float) $values['jumlah'] : (string) $values['jumlah'],
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
            $serial = (float) $value;

            // Di luar rentang serial Excel: biarkan apa adanya supaya gagal di validasi date_format
            if ($serial < self::EXCEL_SERIAL_MIN || $serial > self::EXCEL_SERIAL_MAX) {
                return (string) $value;
            }

            return ExcelDate::excelToDateTimeObject($serial)->format('Y-m-d');
        }

        return trim((string) $value);
    }

    /** Tolak file yang berpotensi menghabiskan memori/CPU sebelum diparse. */
    private function assertSafeToParse(UploadedFile $file): void
    {
        $path = $file->getRealPath();

        if (strtolower($file->getClientOriginalExtension()) === 'xlsx') {
            $zip = new \ZipArchive;
            if ($zip->open($path) !== true) {
                $this->rejectFile('File .xlsx tidak bisa dibaca. Gunakan template yang disediakan.');
            }

            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $total += (int) ($zip->statIndex($i)['size'] ?? 0);
            }
            $zip->close();

            if ($total > self::MAX_XLSX_UNCOMPRESSED_BYTES) {
                $this->rejectFile('Isi file terlalu besar untuk diimport. Maksimal '.self::MAX_ROWS.' baris.');
            }

            return;
        }

        $handle = fopen($path, 'r');
        try {
            $lines = 0;
            while (($line = fgets($handle)) !== false) {
                if (++$lines > self::MAX_CSV_LINES) {
                    $this->rejectFile('File berisi terlalu banyak baris. Maksimal '.self::MAX_ROWS.' baris per import.');
                }

                $separators = max(substr_count($line, ','), substr_count($line, ';'), substr_count($line, "\t"));
                if ($separators >= self::MAX_COLUMNS) {
                    $this->rejectFile('File berisi terlalu banyak kolom. Gunakan kolom sesuai template.');
                }
            }
        } finally {
            fclose($handle);
        }
    }

    private function rejectFile(string $message): never
    {
        throw ValidationException::withMessages(['file' => [$message]]);
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
