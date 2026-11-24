<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Access\Permission;
use App\Domain\Progress\PhotoService;
use App\Http\Controllers\Controller;
use App\Models\Work;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PhotoController extends Controller
{
    public function store(Request $request, PhotoService $photos): JsonResponse
    {
        abort_unless($request->user()->can(Permission::SUBMIT_PROGRESS), 403);

        $data = $request->validate([
            'client_uuid' => ['required', 'uuid'],
            'work_id' => ['required', 'integer'],
            'file' => ['required', 'file', 'mimes:jpg,jpeg', 'max:2048'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'accuracy_m' => ['nullable', 'numeric', 'min:0'],
            'taken_at' => ['nullable', 'date'],
            'is_mocked' => ['nullable', 'boolean'],
        ]);

        $work = Work::query()->findOrFail($data['work_id']); // jurisdiction-scoped
        $photo = $photos->store($request->user(), $work, $request->file('file'), $data);

        return response()->json([
            'client_uuid' => $photo->client_uuid,
            'distance_from_site_m' => $photo->distance_from_site_m,
        ], $photo->wasRecentlyCreated ? 201 : 200);
    }
}
