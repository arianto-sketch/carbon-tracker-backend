<?php

namespace App\Http\Controllers\Api\V1;

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

class CarbonEntryController extends Controller
{
    public function __construct(private CarbonEntryService $service) {}

    public function index(Request $request, int $projectId): JsonResponse
    {
        $project = Project::findOrFail($projectId);
        $this->authorizeProjectAccess($request, $project);

        $entries = $this->service->list(
            $project,
            $request->only(['category_id', 'period_year', 'period_month', 'status'])
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
        $entry = $this->service->submit($entry);

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

    private function authorizeProjectAccess(Request $request, Project $project): void
    {
        if (! $request->user()->isAdmin() && ! $project->hasUser($request->user()->id)) {
            abort(403, 'Akses ditolak. Anda bukan member project ini.');
        }
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

    /**
     * Menulis entri: admin, atau owner/member project yang role globalnya bukan viewer.
     */
    private function authorizeProjectWrite(Request $request, Project $project): void
    {
        $user = $request->user();

        if ($user->isAdmin()) {
            return;
        }

        $projectRole = $project->getUserRole($user->id);

        if ($user->role === 'viewer' || ! in_array($projectRole, ['owner', 'member'], true)) {
            abort(403, 'Akses ditolak. Role Anda hanya bisa melihat entri.');
        }
    }
}
