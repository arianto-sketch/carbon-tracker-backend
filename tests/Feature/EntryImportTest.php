<?php

namespace Tests\Feature;

use App\Models\CarbonEntry;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Facades\Excel;
use Tests\Concerns\BuildsCarbonData;
use Tests\TestCase;

class EntryImportTest extends TestCase
{
    use BuildsCarbonData, RefreshDatabase;

    private const HEADER = 'tanggal,kode_faktor,jumlah,keterangan,vendor,tipe_aktivitas';

    private User $owner;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMasterData();
        $this->travelTo('2026-10-06 10:00:00');
        $this->owner = User::factory()->create();
        $this->project = $this->makeProject($this->owner);
    }

    private function base(): string
    {
        return "/api/v1/projects/{$this->project->id}/entries/import";
    }

    private function csv(array $lines): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('import.csv', implode("\n", [self::HEADER, ...$lines])."\n");
    }

    private function preview(User $as, UploadedFile $file)
    {
        return $this->actingAs($as, 'sanctum')->post("{$this->base()}/preview", ['file' => $file], ['Accept' => 'application/json']);
    }

    public function test_template_contains_import_columns(): void
    {
        $response = $this->actingAs($this->owner, 'sanctum')->get("{$this->base()}/template")->assertOk();
        $response->assertDownload('template-import-entri.xlsx');

        $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        file_put_contents($path, $response->streamedContent());
        $headings = Excel::toArray(new class {}, $path)[0][0];
        @unlink($path);

        $this->assertSame(['tanggal', 'kode_faktor', 'jumlah', 'keterangan', 'vendor', 'tipe_aktivitas'], $headings);
    }

    public function test_preview_reports_errors_per_row_and_computes_emission(): void
    {
        $factor = $this->gasolineFactor();

        $rows = $this->preview($this->owner, $this->csv([
            '2026-03-01,gasoline_vehicle,10,BBM operasional,Pertamina,',
            '2026-03-02,tidak_ada,5,,,',
            '2026-10-07,gasoline_vehicle,5,,,',
            '2026-03-03,gasoline_vehicle,0,,,',
        ]))->assertOk()
            ->assertJsonPath('data.valid_count', 1)
            ->assertJsonPath('data.invalid_count', 3)
            ->json('data.rows');

        $this->assertSame(2, $rows[0]['row']);
        $this->assertSame([], $rows[0]['errors']);
        $this->assertSame($factor->id, $rows[0]['values']['emission_factor_id']);
        $this->assertEqualsWithDelta(10 * (float) $factor->factor_value, $rows[0]['values']['co2e_kg'], 0.0001);

        $this->assertNotEmpty($rows[1]['errors']); // kode faktor tidak dikenal
        $this->assertNotEmpty($rows[2]['errors']); // tanggal di masa depan
        $this->assertNotEmpty($rows[3]['errors']); // jumlah 0
        $this->assertSame(0, CarbonEntry::count());
    }

    public function test_preview_accepts_xlsx(): void
    {
        $xlsx = Excel::raw(new class implements FromArray
        {
            public function array(): array
            {
                return [
                    ['tanggal', 'kode_faktor', 'jumlah', 'keterangan', 'vendor', 'tipe_aktivitas'],
                    ['2026-04-01', 'gasoline_vehicle', 7, 'Dari Excel', '', ''],
                ];
            }
        }, \Maatwebsite\Excel\Excel::XLSX);

        $this->preview($this->owner, UploadedFile::fake()->createWithContent('import.xlsx', $xlsx))
            ->assertOk()
            ->assertJsonPath('data.valid_count', 1)
            ->assertJsonPath('data.rows.0.values.description', 'Dari Excel');
    }

    public function test_preview_rejects_wrong_type_and_too_many_rows(): void
    {
        $this->preview($this->owner, UploadedFile::fake()->create('struk.pdf', 10, 'application/pdf'))
            ->assertStatus(422)->assertJsonValidationErrors('file');

        $this->preview($this->owner, $this->csv(array_fill(0, 501, '2026-03-01,gasoline_vehicle,1,,,')))
            ->assertStatus(422)->assertJsonValidationErrors('file');
    }

    public function test_commit_creates_all_rows_as_draft(): void
    {
        $factor = $this->gasolineFactor();

        $this->actingAs($this->owner, 'sanctum')->postJson($this->base(), ['rows' => [
            ['entry_date' => '2026-03-01', 'emission_factor_id' => $factor->id, 'quantity' => 10, 'description' => 'A'],
            ['entry_date' => '2026-03-02', 'emission_factor_id' => $factor->id, 'quantity' => 20, 'vendor_name' => 'Pertamina'],
        ]])->assertCreated()->assertJsonPath('data.created', 2);

        $this->assertSame(2, CarbonEntry::where('project_id', $this->project->id)->where('status', 'draft')->count());
    }

    public function test_commit_is_all_or_nothing(): void
    {
        $factor = $this->gasolineFactor();

        $this->actingAs($this->owner, 'sanctum')->postJson($this->base(), ['rows' => [
            ['entry_date' => '2026-03-01', 'emission_factor_id' => $factor->id, 'quantity' => 10],
            ['entry_date' => '2026-03-02', 'emission_factor_id' => 999999, 'quantity' => 20],
        ]])->assertStatus(422);

        $this->assertSame(0, CarbonEntry::count());
    }

    public function test_viewer_cannot_import(): void
    {
        $viewer = User::factory()->create();
        $this->addMember($this->project, $viewer, 'viewer');

        $this->preview($viewer, $this->csv(['2026-03-01,gasoline_vehicle,10,,,']))->assertForbidden();
        $this->actingAs($viewer, 'sanctum')->postJson($this->base(), ['rows' => [
            ['entry_date' => '2026-03-01', 'emission_factor_id' => $this->gasolineFactor()->id, 'quantity' => 1],
        ]])->assertForbidden();
    }
}
