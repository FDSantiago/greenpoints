<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminWasteCategoryResource;
use App\Models\WasteCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class WasteCategoryController extends Controller
{
    /** All categories, including inactive ones. */
    public function index(): JsonResponse
    {
        $categories = WasteCategory::withCount('wasteLogs')->orderBy('name')->get();

        return response()->json(['data' => AdminWasteCategoryResource::collection($categories)->resolve()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:waste_categories,name'],
            'is_recyclable' => ['nullable', 'boolean'],
            'points_per_kg' => ['required', 'numeric', 'min:0', 'max:10000'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $category = WasteCategory::create($data + ['is_recyclable' => true, 'is_active' => true]);

        return response()->json(['data' => (new AdminWasteCategoryResource($category))->resolve()], 201);
    }

    public function show(int $categoryId): JsonResponse
    {
        $category = WasteCategory::withCount('wasteLogs')->findOrFail($categoryId);

        return response()->json(['data' => (new AdminWasteCategoryResource($category))->resolve()]);
    }

    /** Rate changes apply to FUTURE verifications only: already-awarded points are snapshots. */
    public function update(Request $request, int $categoryId): JsonResponse
    {
        $category = WasteCategory::findOrFail($categoryId);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('waste_categories', 'name')->ignore($category->id)],
            'is_recyclable' => ['sometimes', 'required', 'boolean'],
            'points_per_kg' => ['sometimes', 'required', 'numeric', 'min:0', 'max:10000'],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ]);

        $category->fill($data)->save();

        return response()->json(['data' => (new AdminWasteCategoryResource(WasteCategory::withCount('wasteLogs')->find($category->id)))->resolve()]);
    }

    public function destroy(int $categoryId): Response
    {
        $category = WasteCategory::findOrFail($categoryId);

        abort_if(
            $category->wasteLogs()->exists(),
            409,
            'This category is used by waste logs and cannot be deleted. Deactivate it instead (PATCH is_active=false).'
        );

        $category->delete();

        return response()->noContent();
    }
}
