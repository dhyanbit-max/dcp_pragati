<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Access\Permission;
use App\Domain\Alerts\AlertActions;
use App\Http\Controllers\Controller;
use App\Models\Alert;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/** docs/12-api-spec.md §6. Jurisdiction scope applies to listing and route binding. */
class AlertController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permission::HANDLE_ALERTS), 403);
        $request->validate(['status' => ['nullable', 'in:open,acknowledged,resolved,auto_closed']]);

        $alerts = Alert::query()
            ->with('work:id,code,name_en,name_as')
            ->where('status', $request->input('status', Alert::STATUS_OPEN))
            ->orderByDesc('level')->orderBy('opened_at')
            ->paginate(50);

        $alerts->getCollection()->transform(fn (Alert $a) => [
            'id' => $a->id,
            'work_id' => $a->work_id,
            'work_code' => $a->work->code,
            'code' => $a->alert_code,
            'title' => __("alert_types.{$a->alert_code}.title"),
            'status' => $a->status,
            'level' => $a->level,
            'opened_at' => $a->opened_at->toIso8601String(),
            'details' => $a->details_json,
        ]);

        return response()->json($alerts);
    }

    public function acknowledge(Request $request, Alert $alert, AlertActions $actions): JsonResponse
    {
        return $this->act($request, fn ($note) => $actions->acknowledge($alert, $request->user(), $note));
    }

    public function resolve(Request $request, Alert $alert, AlertActions $actions): JsonResponse
    {
        return $this->act($request, fn ($note) => $actions->resolve($alert, $request->user(), $note));
    }

    private function act(Request $request, callable $fn): JsonResponse
    {
        abort_unless($request->user()->can(Permission::HANDLE_ALERTS), 403);
        $data = $request->validate(['note' => ['required', 'string', 'max:1000']]);

        try {
            $alert = $fn($data['note']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => ['code' => 'INVALID_STATE', 'message' => $e->getMessage()]], 409);
        }

        return response()->json(['id' => $alert->id, 'status' => $alert->status]);
    }
}
