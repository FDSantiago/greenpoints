<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\WasteCategoryResource;
use App\Models\WasteCategory;
use Illuminate\Http\JsonResponse;

class WasteCategoryController extends Controller
{
    public function index(): JsonResponse
    {
        $categories = WasteCategory::where('is_active', true)->orderBy('name')->get();

        return response()->json([
            'data' => WasteCategoryResource::collection($categories)->resolve(),
            // Lets the frontend preview points: weight * points_per_kg * multiplier.
            'condition_multipliers' => config('greenpoints.condition_multipliers'),
        ]);
    }
}
