<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Access\Permission;
use App\Domain\Progress\ApprovalService;
use App\Http\Controllers\Controller;
use App\Models\ProgressUpdate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class ApprovalController extends Controller
{
    public function __construct(private readonly ApprovalService $approvals) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizeApprover($request);
        $tab = $request->input('tab', 'flagged');

        $items = ProgressUpdate::query()->pending()
            ->where('is_flagged', $tab === 'flagged')
            ->with(['work:id,code,name_en,district_id,co_district_id', 'submitter:id,name', 'ruleResults' => fn ($q) => $q->where('outcome', '!=', 'pass')])
            ->withCount('photos')
            ->oldest('received_at')
            ->paginate(50);

        return response()->json($items);
    }

    public function approve(Request $request, ProgressUpdate $update): JsonResponse
    {
        $this->authorizeApprover($request);
        $data = $request->validate(['note' => [$update->is_flagged ? 'required' : 'nullable', 'string', 'max:1000']]);

        return $this->run(fn () => $this->approvals->approve($update, $request->user(), $data['note'] ?? null));
    }

    public function returnUpdate(Request $request, ProgressUpdate $update): JsonResponse
    {
        $this->authorizeApprover($request);
        $data = $request->validate(['note' => ['required', 'string', 'max:1000']]);

        return $this->run(fn () => $this->approvals->returnToSubmitter($update, $request->user(), $data['note']));
    }

    public function reject(Request $request, ProgressUpdate $update): JsonResponse
    {
        $this->authorizeApprover($request);
        $data = $request->validate(['note' => ['required', 'string', 'max:1000']]);

        return $this->run(fn () => $this->approvals->reject($update, $request->user(), $data['note']));
    }

    public function bulkApprove(Request $request): JsonResponse
    {
        $this->authorizeApprover($request);
        $data = $request->validate(['ids' => ['required', 'array', 'max:500'], 'ids.*' => ['integer']]);

        return response()->json(['approved' => $this->approvals->bulkApprove($data['ids'], $request->user())]);
    }

    private function authorizeApprover(Request $request): void
    {
        abort_unless($request->user()->can(Permission::APPROVE_PROGRESS), 403);
    }

    private function run(callable $action): JsonResponse
    {
        try {
            $update = $action();
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => ['code' => 'INVALID_STATE', 'message' => $e->getMessage()]], 409);
        }

        return response()->json(['id' => $update->id, 'status' => $update->status]);
    }
}
