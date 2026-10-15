<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\ClassGroupResource;
use App\Models\ClassGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class GroupController extends Controller
{
    public function index(): JsonResponse
    {
        $groups = ClassGroup::withCount('users')->orderBy('name')->get();

        return response()->json(['data' => ClassGroupResource::collection($groups)->resolve()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:class_groups,name'],
            'type' => ['nullable', Rule::in(['class', 'zone'])],
        ]);

        $group = ClassGroup::create($data + ['type' => 'class']);

        return response()->json(['data' => (new ClassGroupResource($group))->resolve()], 201);
    }

    public function show(int $groupId): JsonResponse
    {
        $group = ClassGroup::withCount('users')->findOrFail($groupId);

        return response()->json(['data' => (new ClassGroupResource($group))->resolve()]);
    }

    public function update(Request $request, int $groupId): JsonResponse
    {
        $group = ClassGroup::findOrFail($groupId);

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:100', Rule::unique('class_groups', 'name')->ignore($group->id)],
            'type' => ['sometimes', 'required', Rule::in(['class', 'zone'])],
        ]);

        $group->fill($data)->save();

        return response()->json(['data' => (new ClassGroupResource(ClassGroup::withCount('users')->find($group->id)))->resolve()]);
    }

    /** Refused while students belong to the group (otherwise they'd silently become group-less). */
    public function destroy(int $groupId): Response
    {
        $group = ClassGroup::findOrFail($groupId);

        abort_if(
            $group->users()->exists(),
            409,
            'This group still has members. Move or remove them first.'
        );

        $group->delete(); // also removes its stored impact reports (cascade)

        return response()->noContent();
    }
}
