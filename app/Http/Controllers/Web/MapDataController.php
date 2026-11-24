<?php

namespace App\Http\Controllers\Web;

use App\Domain\Gis\MapData;
use App\Http\Controllers\Controller;
use App\Models\Work;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** GeoJSON for the works map. Route binding and queries are jurisdiction-scoped. */
class MapDataController extends Controller
{
    private const FILTERS = ['fy', 'district', 'co_district', 'constituency', 'scheme_type', 'fund_source', 'agency', 'status'];

    public function works(Request $request, MapData $map): JsonResponse
    {
        return $this->geojson($map->works($this->filters($request)));
    }

    public function boundaries(Request $request, string $level, MapData $map): JsonResponse
    {
        abort_unless(isset(MapData::LEVELS[$level]), 404);

        return $this->geojson($map->boundaries($level, $this->filters($request)));
    }

    public function borders(string $level, MapData $map): JsonResponse
    {
        abort_unless(in_array($level, ['district', 'co_district'], true), 404);

        return response()->json($map->borders($level), 200, ['Content-Type' => 'application/geo+json', 'Cache-Control' => 'private, max-age=3600']);
    }

    public function state(MapData $map): JsonResponse
    {
        return response()->json($map->state(), 200, ['Content-Type' => 'application/geo+json', 'Cache-Control' => 'private, max-age=3600']);
    }

    public function work(Work $work, MapData $map): JsonResponse
    {
        return response()->json($map->workDetails($work->load(['district', 'currentStage'])));
    }

    private function filters(Request $request): array
    {
        return array_filter($request->only(self::FILTERS), fn ($v) => $v !== null && $v !== '');
    }

    private function geojson(array $data): JsonResponse
    {
        return response()->json($data, 200, ['Content-Type' => 'application/geo+json', 'Cache-Control' => 'private, max-age=60']);
    }
}
