<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\EmissionFactor\StoreEmissionFactorRequest;
use App\Http\Requests\EmissionFactor\UpdateEmissionFactorRequest;
use App\Http\Resources\EmissionFactorResource;
use App\Models\EmissionFactor;
use App\Services\EmissionFactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmissionFactorController extends Controller
{
    public function __construct(private EmissionFactorService $service) {}

    public function index(Request $request): JsonResponse
    {
        $factors = $this->service->list($request->only(['category_id', 'search', 'is_active']));

        return response()->json([
            'data' => EmissionFactorResource::collection($factors->items()),
            'meta' => [
                'current_page' => $factors->currentPage(),
                'last_page' => $factors->lastPage(),
                'per_page' => $factors->perPage(),
                'total' => $factors->total(),
            ],
        ]);
    }

    public function store(StoreEmissionFactorRequest $request): JsonResponse
    {
        $factor = $this->service->create($request->validated(), $request->user());

        return response()->json([
            'data' => new EmissionFactorResource($factor->load('category')),
            'message' => 'Faktor emisi berhasil dibuat.',
        ], 201);
    }

    public function show(int $id): JsonResponse
    {
        $factor = EmissionFactor::with('category')->findOrFail($id);

        return response()->json(['data' => new EmissionFactorResource($factor)]);
    }

    public function update(UpdateEmissionFactorRequest $request, int $id): JsonResponse
    {
        $factor = EmissionFactor::findOrFail($id);
        $factor = $this->service->update($factor, $request->validated(), $request->user());

        return response()->json([
            'data' => new EmissionFactorResource($factor),
            'message' => 'Faktor emisi berhasil diperbarui.',
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $factor = EmissionFactor::findOrFail($id);
        $this->service->deactivate($factor, $request->user());

        return response()->json(['message' => 'Faktor emisi berhasil dinonaktifkan.']);
    }
}
