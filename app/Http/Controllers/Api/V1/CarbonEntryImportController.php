<?php

namespace App\Http\Controllers\Api\V1;

use App\Exports\CarbonEntryImportTemplate;
use App\Http\Controllers\Concerns\AuthorizesProjectEntries;
use App\Http\Controllers\Controller;
use App\Http\Requests\CarbonEntry\ImportCarbonEntriesRequest;
use App\Models\Project;
use App\Services\CarbonEntryImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class CarbonEntryImportController extends Controller
{
    use AuthorizesProjectEntries;

    public function __construct(private CarbonEntryImportService $service) {}

    public function template(Request $request, int $projectId)
    {
        $this->authorizeProjectAccess($request, Project::findOrFail($projectId));

        return Excel::download(new CarbonEntryImportTemplate, 'template-import-entri.xlsx');
    }

    public function preview(Request $request, int $projectId): JsonResponse
    {
        $this->authorizeProjectWrite($request, Project::findOrFail($projectId));

        // extensions: ekstensi nama file (menentukan reader); mimes: isi file sebenarnya
        $request->validate(
            ['file' => ['required', 'file', 'extensions:xlsx,csv', 'mimes:xlsx,csv,txt', 'max:2048']],
            [
                'file.required'   => 'File import wajib dipilih.',
                'file.extensions' => 'File harus berformat .xlsx atau .csv.',
                'file.mimes'      => 'File harus berformat .xlsx atau .csv.',
                'file.max'        => 'Ukuran file maksimal 2 MB.',
            ],
        );

        return response()->json(['data' => $this->service->preview($request->file('file'))]);
    }

    public function store(ImportCarbonEntriesRequest $request, int $projectId): JsonResponse
    {
        $project = Project::findOrFail($projectId);
        $this->authorizeProjectWrite($request, $project);

        $created = $this->service->commit($request->validated('rows'), $project, $request->user());

        return response()->json([
            'data'    => ['created' => $created],
            'message' => "{$created} entri berhasil diimport sebagai draft.",
        ], 201);
    }
}
