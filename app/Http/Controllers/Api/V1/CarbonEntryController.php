<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\AuthorizesProjectEntries;
use App\Http\Controllers\Controller;
use App\Http\Requests\CarbonEntry\BulkStoreCarbonEntryRequest;
use App\Http\Requests\CarbonEntry\StoreCarbonEntryRequest;
use App\Http\Requests\CarbonEntry\UpdateCarbonEntryRequest;
use App\Http\Resources\CarbonEntryResource;
use App\Models\CarbonEntry;
use App\Models\Project;
use App\Services\CarbonEntryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class CarbonEntryController extends Controller
{
    use AuthorizesProjectEntries;

    public function __construct(private CarbonEntryService $service) {}

    public function index(Request $request, int $projectId): JsonResponse
    {
        $project = Project::findOrFail($projectId);
        $this->authorizeProjectAccess($request, $project);

        $entries = $this->service->list(
            $project,
            $request->only(['category_id', 'period_year', 'period_month', 'status']),
            $this->perPage($request, 20)
        );

        return response()->json([
            'data' => CarbonEntryResource::collection($entries->items()),
            'meta' => [
                'current_page' => $entries->currentPage(),
                'last_page'    => $entries->lastPage(),
                'per_page'     => $entries->perPage(),
                'total'        => $entries->total(),
            ],
        ]);
    }

    public function store(StoreCarbonEntryRequest $request, int $projectId): JsonResponse
    {
        $project = Project::findOrFail($projectId);
        $this->authorizeProjectWrite($request, $project);

        $entry = $this->service->create($request->validated(), $project, $request->user());

        return response()->json([
            'data'    => new CarbonEntryResource($entry->load(['emissionFactor', 'category', 'createdBy'])),
            'message' => 'Entry emisi berhasil ditambahkan.',
        ], 201);
    }

    public function show(Request $request, int $projectId, int $id): JsonResponse
    {
        $project = Project::findOrFail($projectId);
        $this->authorizeProjectAccess($request, $project);

        $entry = CarbonEntry::with(['emissionFactor', 'category', 'createdBy', 'approvedBy', 'rejectedBy'])
            ->where('project_id', $projectId)
            ->findOrFail($id);

        return response()->json(['data' => new CarbonEntryResource($entry)]);
    }

    public function history(Request $request, int $projectId, int $id): JsonResponse
    {
        $project = Project::findOrFail($projectId);
        $this->authorizeProjectAccess($request, $project);

        $entry = CarbonEntry::where('project_id', $projectId)->findOrFail($id);

        return response()->json(['data' => $this->service->history($entry)]);
    }

    public function uploadAttachment(Request $request, int $projectId, int $id): JsonResponse
    {
        $project = Project::findOrFail($projectId);
        $this->authorizeProjectWrite($request, $project);
        $entry = CarbonEntry::where('project_id', $projectId)->findOrFail($id);

        $request->validate(
            ['file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120']],
            [
                'file.required' => 'File lampiran wajib dipilih.',
                'file.mimes'    => 'Lampiran harus berupa PDF, JPG, atau PNG.',
                'file.max'      => 'Ukuran lampiran maksimal 5 MB.',
            ],
        );

        $entry = $this->service->attach($entry, $request->file('file'), $request->user());

        return response()->json([
            'data'    => new CarbonEntryResource($entry),
            'message' => 'Lampiran berhasil diunggah.',
        ]);
    }

    public function downloadAttachment(Request $request, int $projectId, int $id)
    {
        $project = Project::findOrFail($projectId);
        $this->authorizeProjectAccess($request, $project);
        $entry = CarbonEntry::where('project_id', $projectId)->findOrFail($id);

        $disk = Storage::disk($this->service->attachmentDisk());

        if (! $entry->attachment_path || ! $disk->exists($entry->attachment_path)) {
            return response()->json(['message' => 'Entri ini belum punya lampiran.'], 404);
        }

        return $disk->download($entry->attachment_path, $entry->attachmentDownloadName());
    }

    public function deleteAttachment(Request $request, int $projectId, int $id): JsonResponse
    {
        $project = Project::findOrFail($projectId);
        $this->authorizeProjectWrite($request, $project);
        $entry = CarbonEntry::where('project_id', $projectId)->findOrFail($id);

        $entry = $this->service->detach($entry, $request->user());

        return response()->json([
            'data'    => new CarbonEntryResource($entry),
            'message' => 'Lampiran berhasil dihapus.',
        ]);
    }

    public function update(UpdateCarbonEntryRequest $request, int $projectId, int $id): JsonResponse
    {
        $project = Project::findOrFail($projectId);
        $this->authorizeProjectWrite($request, $project);

        $entry = CarbonEntry::where('project_id', $projectId)->findOrFail($id);
        $entry = $this->service->update($entry, $request->validated(), $request->user());

        return response()->json([
            'data'    => new CarbonEntryResource($entry),
            'message' => 'Entry emisi berhasil diperbarui.',
        ]);
    }

    public function destroy(Request $request, int $projectId, int $id): JsonResponse
    {
        $project = Project::findOrFail($projectId);
        $this->authorizeProjectWrite($request, $project);

        $entry = CarbonEntry::where('project_id', $projectId)->findOrFail($id);
        $this->service->delete($entry);

        return response()->json(['message' => 'Entry emisi berhasil dihapus.']);
    }

    public function submit(Request $request, int $projectId, int $id): JsonResponse
    {
        $project = Project::findOrFail($projectId);
        $this->authorizeProjectWrite($request, $project);

        $entry = CarbonEntry::where('project_id', $projectId)->findOrFail($id);
        $entry = $this->service->submit($entry, $request->user());

        return response()->json([
            'data'    => new CarbonEntryResource($entry),
            'message' => 'Entry berhasil di-submit untuk approval.',
        ]);
    }

    public function approve(Request $request, int $projectId, int $id): JsonResponse
    {
        $project = Project::findOrFail($projectId);
        $entry = CarbonEntry::where('project_id', $projectId)->findOrFail($id);
        $this->authorizeReview($request, $project, $entry);

        $entry = $this->service->approve($entry, $request->user());

        return response()->json([
            'data'    => new CarbonEntryResource($entry->load('approvedBy')),
            'message' => 'Entry berhasil di-approve.',
        ]);
    }

    public function reject(Request $request, int $projectId, int $id): JsonResponse
    {
        $project = Project::findOrFail($projectId);
        $entry = CarbonEntry::where('project_id', $projectId)->findOrFail($id);
        $this->authorizeReview($request, $project, $entry);

        $data = $request->validate(
            ['reason' => ['required', 'string', 'max:500']],
            ['reason.required' => 'Alasan penolakan wajib diisi.', 'reason.max' => 'Alasan penolakan maksimal 500 karakter.'],
        );

        $entry = $this->service->reject($entry, $request->user(), $data['reason']);

        return response()->json([
            'data'    => new CarbonEntryResource($entry),
            'message' => 'Entry ditolak dan dikembalikan ke pembuat.',
        ]);
    }

    public function bulk(BulkStoreCarbonEntryRequest $request, int $projectId): JsonResponse
    {
        $project = Project::findOrFail($projectId);
        $this->authorizeProjectWrite($request, $project);

        $results = $this->service->bulkCreate($request->entries, $project, $request->user());

        return response()->json([
            'data'    => $results,
            'message' => count($results['created']) . ' entry berhasil dibuat, ' . count($results['errors']) . ' gagal.',
        ], 201);
    }

    /**
     * Approve/tolak: owner project atau admin, dan bukan pembuat maupun pengubah terakhir entri (prinsip 4-eyes).
     */
    private function authorizeReview(Request $request, Project $project, CarbonEntry $entry): void
    {
        $user = $request->user();

        if (! $user->isAdmin() && $project->getUserRole($user->id) !== 'owner') {
            abort(403, 'Hanya owner atau admin yang bisa me-review entry.');
        }

        if ((int) $entry->created_by === (int) $user->id) {
            abort(403, 'Tidak bisa me-review entri buatan sendiri.');
        }

        // updated_by = pengubah isi terakhir (submit/approve/reject tidak mengubahnya)
        if ($entry->updated_by !== null && (int) $entry->updated_by === (int) $user->id) {
            abort(403, 'Tidak bisa me-review entri yang isinya terakhir Anda ubah.');
        }
    }
}
