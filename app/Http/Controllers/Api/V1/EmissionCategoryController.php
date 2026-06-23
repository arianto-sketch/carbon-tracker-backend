<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\EmissionCategoryResource;
use App\Models\EmissionCategory;
use Illuminate\Http\JsonResponse;

class EmissionCategoryController extends Controller
{
    public function index(): JsonResponse
    {
        $categories = EmissionCategory::where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        return response()->json([
            'data' => EmissionCategoryResource::collection($categories),
        ]);
    }
}
