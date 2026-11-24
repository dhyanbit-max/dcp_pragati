<?php

namespace App\Domain\Gis;

use App\Domain\Access\JurisdictionScope;
use App\Domain\Monitoring\WorkStatus;
use App\Models\ProgressPhoto;
use App\Models\ProgressUpdate;
use App\Models\Work;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * GeoJSON and area figures for the works map (docs/10). Everything starts from
 * WorkStatus::filtered(), so the viewer's jurisdiction scope always applies.
 */
class MapData
{
    public const LEVELS = [
        'district' => ['districts', 'district_id'],
        'co_district' => ['co_districts', 'co_district_id'],
        'constituency' => ['constituencies', 'constituency_id'],
    ];

    /** Bump when the border feature properties change. */
    private const BORDERS_FORMAT = '2';

    public function __construct(private readonly WorkStatus $status) {}

    /** Works with a site, as a GeoJSON FeatureCollection (EPSG:4326). */
    public function works(array $filters): array
    {
        $loc = app()->getLocale();
        $rows = $this->status->withFlags($this->status->filtered($filters))
            ->whereNotNull('works.site')
            ->addSelect(DB::raw('ST_AsGeoJSON(works.site, 6) AS geom'))
            ->get();

        return $this->collection($rows->map(fn (Work $w) => [
            'type' => 'Feature',
            'id' => $w->id,
            'geometry' => json_decode($w->geom, true),
            'properties' => [
                'id' => $w->id,
                'code' => $w->code,
                'name' => ($loc === 'as' && $w->name_as) ? $w->name_as : $w->name_en,
                'display' => self::display($w->status, (bool) $w->is_delayed, (bool) $w->is_stalled),
                'stale' => (bool) $w->is_stale,
                'mismatch' => (bool) $w->is_mismatch,
                'physical_pct' => $w->physical_pct,
                'financial_pct' => (float) $w->financial_pct,
                'radius_m' => $w->site_radius_m,
            ],
        ]));
    }

    /** One map state per work. Stalled and delayed outrank the stored status. */
    public static function display(string $status, bool $delayed, bool $stalled): string
    {
        return match (true) {
            $status === Work::STATUS_COMPLETED => 'completed',
            $status === Work::STATUS_CANCELLED => 'cancelled',
            $stalled || $status === Work::STATUS_STALLED => 'stalled',
            $delayed => 'delayed',
            $status === Work::STATUS_IN_PROGRESS => 'in_progress',
            default => 'not_started',
        };
    }

    /**
     * Per-area counts for the choropleth and the accessible table: works, delayed or stalled, share.
     *
     * @return Collection<int, array{id:int|null, name:string, works:int, flagged:int, flagged_pct:float}>
     */
    public function areas(string $level, array $filters): Collection
    {
        [$table, $fk] = self::LEVELS[$level] ?? self::LEVELS['district'];
        $loc = app()->getLocale();
        $f = $this->status->flagSql();

        return $this->status->filtered($filters)->toBase()
            ->leftJoin("$table as a", 'a.id', '=', "works.$fk")
            ->groupBy('a.id')
            ->orderByRaw('MIN(a.name_en) NULLS LAST')
            ->selectRaw("
                a.id AS id,
                COALESCE(MIN(a.name_$loc), MIN(a.name_en)) AS name,
                COUNT(*) AS works,
                COUNT(*) FILTER (WHERE {$f['delayed']} OR {$f['stalled']}) AS flagged
            ")->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'name' => $r->name ?? __('Not assigned'),
                'works' => (int) $r->works,
                'flagged' => (int) $r->flagged,
                'flagged_pct' => $r->works > 0 ? round($r->flagged * 100 / $r->works, 1) : 0.0,
            ]);
    }

    /** Area boundaries with their figures, simplified for the web. Only areas that hold works in scope. */
    public function boundaries(string $level, array $filters): array
    {
        [$table] = self::LEVELS[$level] ?? self::LEVELS['district'];
        $areas = $this->areas($level, $filters)->filter(fn ($a) => $a['id'] !== null)->keyBy('id');

        $geoms = DB::table($table)->whereIn('id', $areas->keys())->whereNotNull('boundary')
            ->selectRaw('id, ST_AsGeoJSON(ST_SimplifyPreserveTopology(boundary, 0.0005), 5) AS geom')
            ->pluck('geom', 'id');

        return $this->collection($geoms->map(fn ($geom, $id) => [
            'type' => 'Feature',
            'id' => "$level-$id",
            'geometry' => json_decode($geom, true),
            'properties' => $areas[$id],
        ])->values());
    }

    /**
     * Every loaded outline at a level, for the border lines (not only areas with works, unlike boundaries()).
     * Borders are geography, not monitoring data: no figures are attached, so no jurisdiction filter is needed.
     */
    public function borders(string $level): array
    {
        [$table] = self::LEVELS[$level] ?? self::LEVELS['district'];
        $loc = app()->getLocale();
        $loc = in_array($loc, ['as', 'bn'], true) ? $loc : 'en';
        $tolerance = $level === 'district' ? 0.0005 : 0.0003;

        $rows = DB::table($table)->whereNotNull('boundary')
            ->selectRaw('id, name_en, name_'.$loc.' AS name_local, '.($level === 'co_district' ? 'district_id' : 'NULL').' AS parent_id, ST_AsGeoJSON(ST_SimplifyPreserveTopology(boundary, ?), 5) AS geom', [$tolerance])
            ->orderBy('name_en')->get();

        return $this->collection($rows->map(fn ($r) => [
            'type' => 'Feature',
            'id' => "border-$level-{$r->id}",
            'geometry' => json_decode($r->geom, true),
            'properties' => ['id' => $r->id, 'name' => $r->name_local ?: $r->name_en, 'parent_id' => $r->parent_id],
        ]));
    }

    /** Changes when outlines are imported or the border format changes, so cached border files are not reused. */
    public function bordersVersion(): string
    {
        $stamp = DB::selectOne('SELECT (SELECT MAX(updated_at) FROM districts)::text AS d, (SELECT MAX(updated_at) FROM co_districts)::text AS c');

        return substr(md5(self::BORDERS_FORMAT.'|'.$stamp->d.'|'.$stamp->c), 0, 10);
    }

    /** The state outline (config dcp.map.state_lgd_code) for the map highlight; empty if not loaded. */
    public function state(): array
    {
        $row = DB::table('states')->where('lgd_code', config('dcp.map.state_lgd_code'))->whereNotNull('boundary')
            ->selectRaw('lgd_code, name_en, name_as, name_bn, boundary_source, ST_AsGeoJSON(ST_SimplifyPreserveTopology(boundary, 0.0003), 5) AS geom')
            ->first();
        if (! $row) {
            return $this->collection(collect());
        }
        $loc = app()->getLocale();

        return $this->collection(collect([[
            'type' => 'Feature',
            'id' => "state-{$row->lgd_code}",
            'geometry' => json_decode($row->geom, true),
            'properties' => ['name' => $row->{"name_$loc"} ?? $row->name_en, 'source' => $row->boundary_source],
        ]]));
    }

    /** Popup details for one work: figures, photo points and the latest photos. Caller loads $work through the scope. */
    public function workDetails(Work $work): array
    {
        $loc = app()->getLocale();
        $photos = ProgressPhoto::withoutGlobalScope(JurisdictionScope::class)
            ->where('work_id', $work->id)
            ->whereIn('progress_update_id', ProgressUpdate::withoutGlobalScope(JurisdictionScope::class)
                ->where('work_id', $work->id)->whereIn('status', [ProgressUpdate::STATUS_ACCEPTED, ProgressUpdate::STATUS_PENDING])->select('id'))
            ->latest('taken_at')->limit(12)->get();

        $site = DB::selectOne('SELECT ST_Y(site) AS lat, ST_X(site) AS lng FROM works WHERE id = ?', [$work->id]);

        return [
            'id' => $work->id,
            'code' => $work->code,
            'name' => ($loc === 'as' && $work->name_as) ? $work->name_as : $work->name_en,
            'district' => $work->district?->name($loc),
            'stage' => $work->currentStage?->name($loc),
            'physical_pct' => $work->physical_pct,
            'financial_pct' => $work->financialPct(),
            'last_update' => $work->last_accepted_update_at?->format('d M Y'),
            'url' => route('works.show', $work),
            'site' => $site?->lat !== null ? ['lat' => (float) $site->lat, 'lng' => (float) $site->lng] : null,
            'radius_m' => $work->site_radius_m,
            'photos' => $photos->map(fn (ProgressPhoto $p) => [
                'url' => route('photos.show', $p),
                'lat' => $p->lat,
                'lng' => $p->lng,
                'taken' => $p->taken_at?->format('d M Y'),
                'distance_m' => $p->distance_from_site_m,
                'outside' => $p->distance_from_site_m !== null && $p->distance_from_site_m > $work->site_radius_m,
            ])->values(),
        ];
    }

    private function collection(Collection $features): array
    {
        return ['type' => 'FeatureCollection', 'features' => $features->values()->all()];
    }
}
