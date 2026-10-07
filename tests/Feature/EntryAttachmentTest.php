<?php

namespace Tests\Feature;

use App\Models\CarbonEntry;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsCarbonData;
use Tests\TestCase;

class EntryAttachmentTest extends TestCase
{
    use BuildsCarbonData, RefreshDatabase;

    private User $owner;

    private User $member;

    private Project $project;

    private CarbonEntry $entry;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->seedMasterData();
        $this->owner = User::factory()->create();
        $this->member = User::factory()->create();
        $this->project = $this->makeProject($this->owner);
        $this->addMember($this->project, $this->member, 'member');
        $this->entry = $this->makeEntry($this->project, $this->member, 'draft');
    }

    private function url(?CarbonEntry $entry = null): string
    {
        $entry ??= $this->entry;

        return "/api/v1/projects/{$this->project->id}/entries/{$entry->id}/attachment";
    }

    private function upload(User $as, UploadedFile $file, ?CarbonEntry $entry = null)
    {
        return $this->actingAs($as, 'sanctum')->post($this->url($entry), ['file' => $file], ['Accept' => 'application/json']);
    }

    private function pdf(string $name = 'struk.pdf', int $kb = 100): UploadedFile
    {
        return UploadedFile::fake()->create($name, $kb, 'application/pdf');
    }

    public function test_member_uploads_attachment_to_editable_entry(): void
    {
        $this->upload($this->member, $this->pdf())
            ->assertOk()
            ->assertJsonPath('data.has_attachment', true)
            ->assertJsonPath('data.attachment_name', 'struk.pdf')
            ->assertJsonMissingPath('data.attachment_path');

        $path = $this->entry->fresh()->attachment_path;
        $this->assertStringStartsWith("attachments/{$this->project->id}/{$this->entry->id}/", $path);
        Storage::disk('local')->assertExists($path);
    }

    public function test_replacing_attachment_deletes_old_file(): void
    {
        $this->upload($this->member, $this->pdf('lama.pdf'))->assertOk();
        $old = $this->entry->fresh()->attachment_path;

        $this->upload($this->member, UploadedFile::fake()->image('baru.jpg'))
            ->assertOk()
            ->assertJsonPath('data.attachment_name', 'baru.jpg');

        Storage::disk('local')->assertMissing($old);
        Storage::disk('local')->assertExists($this->entry->fresh()->attachment_path);
    }

    public function test_invalid_type_and_oversized_files_are_rejected(): void
    {
        $this->upload($this->member, UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload'))
            ->assertStatus(422)->assertJsonValidationErrors('file');

        $this->upload($this->member, $this->pdf('besar.pdf', 6000))
            ->assertStatus(422)->assertJsonValidationErrors('file');

        $this->assertNull($this->entry->fresh()->attachment_path);
    }

    public function test_viewer_cannot_upload(): void
    {
        $viewer = User::factory()->create();
        $this->addMember($this->project, $viewer, 'viewer');

        $this->upload($viewer, $this->pdf())->assertForbidden();
    }

    public function test_cannot_change_attachment_of_approved_entry(): void
    {
        $approved = $this->makeApprovedEntry($this->project, $this->member);

        $this->upload($this->member, $this->pdf(), $approved)->assertStatus(422)->assertJsonValidationErrors('status');
        $this->actingAs($this->member, 'sanctum')->deleteJson($this->url($approved))->assertStatus(422);
    }

    public function test_members_can_download_but_outsiders_cannot(): void
    {
        $this->upload($this->member, $this->pdf())->assertOk();

        $this->actingAs($this->owner, 'sanctum')->get($this->url())
            ->assertOk()
            ->assertDownload('struk.pdf');

        $this->actingAs(User::factory()->create(), 'sanctum')->getJson($this->url())->assertForbidden();
    }

    public function test_download_name_uses_detected_type_instead_of_uploader_extension(): void
    {
        // Isi file PDF valid, tapi nama dari pengunggah berekstensi .bat
        $this->upload($this->member, $this->pdf('struk.bat'))->assertOk();
        $this->actingAs($this->owner, 'sanctum')->get($this->url())->assertOk()->assertDownload('struk.pdf');

        $this->upload($this->member, $this->pdf('struk'))->assertOk();
        $this->actingAs($this->owner, 'sanctum')->get($this->url())->assertOk()->assertDownload('struk.pdf');
    }

    public function test_download_without_attachment_is_not_found(): void
    {
        $this->actingAs($this->member, 'sanctum')->getJson($this->url())
            ->assertNotFound()
            ->assertJsonPath('message', 'Entri ini belum punya lampiran.');
    }

    public function test_member_deletes_attachment(): void
    {
        $this->upload($this->member, $this->pdf())->assertOk();
        $path = $this->entry->fresh()->attachment_path;

        $this->actingAs($this->member, 'sanctum')->deleteJson($this->url())
            ->assertOk()
            ->assertJsonPath('data.has_attachment', false);

        Storage::disk('local')->assertMissing($path);
    }

    public function test_history_does_not_expose_internal_attachment_path(): void
    {
        $this->upload($this->member, $this->pdf())->assertOk();

        $history = $this->actingAs($this->member, 'sanctum')
            ->getJson("/api/v1/projects/{$this->project->id}/entries/{$this->entry->id}/history")
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('attachments/', $history);
        $this->assertStringContainsString('struk.pdf', $history);
    }
}
