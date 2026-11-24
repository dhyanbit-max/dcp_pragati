<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Access\Permission;
use App\Domain\Progress\MissingPhotosException;
use App\Domain\Progress\ProgressInput;
use App\Domain\Progress\ProgressSubmissionService;
use App\Http\Controllers\Controller;
use App\Models\ProgressUpdate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProgressUpdateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate(['status' => ['nullable', 'string'], 'work_id' => ['nullable', 'integer']]);
        $user = $request->user();

        $updates = ProgressUpdate::query()
            ->when(! $user->can(Permission::APPROVE_PROGRESS), fn ($q) => $q->where('submitted_by', $user->id))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('work_id'), fn ($q) => $q->where('work_id', $request->integer('work_id')))
            ->latest('id')
            ->paginate(50);

        return response()->json($updates);
    }

    public function store(Request $request, ProgressSubmissionService $service): JsonResponse
    {
        abort_unless($request->user()->can(Permission::SUBMIT_PROGRESS), 403);

        $data = $request->validate([
            'client_uuid' => ['required', 'uuid'],
            'work_id' => ['required', 'integer'],
            'stage_item_id' => ['required', 'integer', 'exists:stage_template_items,id'],
            'physical_pct' => ['required', 'numeric', 'between:0,100'],
            'spent_cumulative' => ['required', 'integer', 'min:0'],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'captured_at' => ['required', 'date'],
            'form_seconds' => ['nullable', 'integer', 'min:0'],
            'device' => ['nullable', 'array'],
            'device.lat' => ['nullable', 'numeric', 'between:-90,90'],
            'device.lng' => ['nullable', 'numeric', 'between:-180,180'],
            'device.accuracy_m' => ['nullable', 'numeric', 'min:0'],
            'device.app_version' => ['nullable', 'string', 'max:20'],
            'photo_uuids' => ['array', 'max:10'],
            'photo_uuids.*' => ['uuid'],
        ]);

        try {
            $result = $service->submit($request->user(), ProgressInput::fromArray($data, 'app'));
        } catch (MissingPhotosException $e) {
            return response()->json(['error' => [
                'code' => 'PHOTOS_NOT_UPLOADED',
                'message' => $e->getMessage(),
                'details' => ['missing' => $e->missing],
            ]], 409);
        }

        $status = match (true) {
            $result->replayed => 200,
            $result->rejected() => 422,
            default => 201,
        };

        return response()->json($result->toArray(), $status);
    }
}
