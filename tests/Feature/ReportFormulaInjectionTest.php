<?php

namespace Tests\Feature;

use App\Jobs\GenerateReportJob;
use App\Models\ReportJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Concerns\BuildsCarbonData;
use Tests\TestCase;

/**
 * Teks isian user yang diawali karakter formula tidak boleh dieksekusi spreadsheet
 * saat laporan dibuka (CSV/formula injection).
 */
class ReportFormulaInjectionTest extends TestCase
{
    use BuildsCarbonData, RefreshDatabase;

    private const PROJECT = '-2+3';

    private const DESCRIPTION = '=HYPERLINK("http://evil.test","Klik")';

    private const VENDOR = "+cmd|' /C calc'!A0";

    private const ACTIVITY = '=1+1';

    private const CREATOR = '@SUM(1+1)';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seedMasterData();
        $creator = User::factory()->create(['name' => self::CREATOR]);
        $project = $this->makeProject($creator);
        $project->update(['name' => self::PROJECT]);
        $this->makeApprovedEntry($project, $creator)->update([
            'description'   => self::DESCRIPTION,
            'vendor_name'   => self::VENDOR,
            'activity_type' => self::ACTIVITY,
        ]);
    }

    private function generate(string $format): string
    {
        $reportJob = ReportJob::create([
            'user_id' => $this->admin->id,
            'filters' => [],
            'format'  => $format,
            'status'  => 'pending',
        ]);

        GenerateReportJob::dispatchSync($reportJob);

        return Storage::disk('local')->path($reportJob->fresh()->file_path);
    }

    public function test_xlsx_stores_formula_like_text_as_plain_text_cells(): void
    {
        $sheet = IOFactory::load($this->generate('xlsx'))->getActiveSheet();

        // Baris 2 = data. B = Project, K = Keterangan, L = Vendor, M = Tipe Aktivitas, N = Di-input oleh
        $texts = ['B2' => self::PROJECT, 'K2' => self::DESCRIPTION, 'L2' => self::VENDOR, 'M2' => self::ACTIVITY, 'N2' => self::CREATOR];
        foreach ($texts as $cell => $text) {
            $this->assertSame(DataType::TYPE_STRING, $sheet->getCell($cell)->getDataType(), "Sel {$cell}");
            $this->assertSame($text, $sheet->getCell($cell)->getValue(), "Sel {$cell} harus tetap utuh");
        }

        // Angka (Jumlah, Faktor, Emisi) tetap numerik supaya bisa dijumlah di Excel
        foreach (['G2', 'I2', 'J2'] as $cell) {
            $this->assertSame(DataType::TYPE_NUMERIC, $sheet->getCell($cell)->getDataType(), "Sel {$cell}");
        }
    }

    public function test_csv_prefixes_formula_triggers_with_apostrophe(): void
    {
        $rows = array_map('str_getcsv', file($this->generate('csv'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        $row = $rows[1];

        $this->assertSame("'".self::PROJECT, $row[1]);
        $this->assertSame("'".self::DESCRIPTION, $row[10]);
        $this->assertSame("'".self::VENDOR, $row[11]);
        $this->assertSame("'".self::ACTIVITY, $row[12]);
        $this->assertSame("'".self::CREATOR, $row[13]);

        // Nilai biasa dan angka tidak ikut diberi prefix
        $this->assertSame('Bensin (kendaraan)', $row[3]);
        $this->assertTrue(is_numeric($row[9]), 'Kolom emisi harus tetap angka');
    }
}
