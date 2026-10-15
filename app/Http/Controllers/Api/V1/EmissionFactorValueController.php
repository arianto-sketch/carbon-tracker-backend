<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\EmissionFactor\StoreFactorValueRequest;
use App\Http\Requests\EmissionFactor\UpdateFactorValueRequest;
use App\Models\EmissionFactor;
use App\Models\EmissionFactorValue;
use App\Services\EmissionFactorValueService;
use Illuminate\Http\JsonResponse;

/**
 * Nilai faktor emisi per periode. meta.recalculated = entri draft/ditolak yang dihitung ulang;
 * meta.locked = entri submitted/approved yang tetap memakai nilai lama.
 */
class EmissionFactorValueController extends Controller
{
    public function __construct(private EmissionFactorValueService $service) {}

    public function index(int $id): JsonResponse
    {
        $factor = EmissionFactor::findOrFail($id);

        return response()->json([
            'data' => $this->service->rows($factor),
            'meta' => ['locked' => $this->service->lockedCount($factor)],
        ]);
    }

    public function store(StoreFactorValueRequest $request, int $id): JsonResponse
    {
        $factor = EmissionFactor::findOrFail($id);
        $result = $this->service->add($factor, $request->validated(), $request->user());

        return response()->json([
            'data'    => collect($this->service->rows($factor))->firstWhere('id', $result['value']->id),
            'meta'    => ['recalculated' => $result['recalculated'], 'locked' => $result['locked']],
            'message' => 'Nilai periode baru disimpan.',
        ], 201);
    }

    public function update(UpdateFactorValueRequest $request, int $id, int $valueId): JsonResponse
    {
        $factor = EmissionFactor::findOrFail($id);
        $result = $this->service->update($factor, $this->period($factor, $valueId), $request->validated());

        return response()->json(['meta' => $result, 'message' => 'Nilai periode diperbarui.']);
    }

    public function destroy(int $id, int $valueId): JsonResponse
    {
        $factor = EmissionFactor::findOrFail($id);
        $result = $this->service->delete($factor, $this->period($factor, $valueId));

        return response()->json(['meta' => $result, 'message' => 'Periode dihapus.']);
    }

    private function period(EmissionFactor $factor, int $valueId): EmissionFactorValue
    {
        return $factor->values()->whereKey($valueId)->firstOrFail();
    }
}
