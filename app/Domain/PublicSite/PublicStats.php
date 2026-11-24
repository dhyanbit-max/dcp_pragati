<?php

namespace App\Domain\PublicSite;

use App\Models\Work;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Figures for the public landing page (docs/04 §4): statewide totals from approved data only, no list of works,
 * no names, remarks or photos. Works' figures change only when a DPC approves an update, so they are
 * approved data by construction. Cached for 10 minutes; the page shows the "as of" time.
 */
class PublicStats
{
    public function summary(): array
    {
        return Cache::remember('public-stats:'.app()->getLocale(), now()->addMinutes(10), function () {
            $active = Work::withoutGlobalScopes()->where('works.status', '!=', Work::STATUS_CANCELLED);
            $t = (clone $active)->toBase()->selectRaw("
                COUNT(*) AS works,
                COUNT(*) FILTER (WHERE status = 'completed') AS completed,
                COUNT(*) FILTER (WHERE status IN ('in_progress', 'stalled')) AS ongoing,
                COUNT(DISTINCT district_id) AS districts,
                COALESCE(SUM(sanctioned_amount), 0) AS sanctioned,
                COALESCE(SUM(spent_total), 0) AS spent,
                ROUND(AVG(physical_pct), 1) AS physical,
                MAX(last_accepted_update_at) AS last_update
            ")->first();

            $loc = in_array(app()->getLocale(), ['as', 'bn'], true) ? app()->getLocale() : 'en';
            $schemes = (clone $active)->toBase()
                ->join('fund_sources as f', 'f.id', '=', 'works.fund_source_id')
                ->groupBy('f.id', 'f.name_en', "f.name_$loc")
                ->orderByRaw('COUNT(*) DESC')
                ->selectRaw("COALESCE(f.name_$loc, f.name_en) AS name, COUNT(*) AS works,
                    COUNT(*) FILTER (WHERE works.status = 'completed') AS completed,
                    COALESCE(SUM(works.sanctioned_amount), 0) AS sanctioned")
                ->get()->map(fn ($r) => (array) $r)->all();

            return [
                'works' => (int) $t->works,
                'completed' => (int) $t->completed,
                'ongoing' => (int) $t->ongoing,
                'districts' => (int) $t->districts,
                'sanctioned' => (int) $t->sanctioned,
                'spent' => (int) $t->spent,
                'physical' => $t->physical !== null ? (float) $t->physical : null,
                'last_update' => $t->last_update,
                'schemes' => $schemes,
                'as_of' => now(),
            ];
        });
    }

    /**
     * Assam with its districts as SVG paths (Web Mercator, y flipped by ST_AsSVG), with average physical progress
     * per district when $withFigures. Null when no state outline is loaded.
     *
     * @return array{viewBox:string, state:string, districts:list<array{name:string, path:string, works:int, physical:?float}>}|null
     */
    public function map(bool $withFigures): ?array
    {
        return Cache::remember('public-map:'.app()->getLocale().':'.(int) $withFigures, now()->addMinutes(10), function () use ($withFigures) {
            $state = DB::selectOne("
                SELECT ST_AsSVG(g, 0, 0) AS path, ST_XMin(g) AS x0, ST_YMax(g) AS y1, ST_XMax(g) - ST_XMin(g) AS w, ST_YMax(g) - ST_YMin(g) AS h
                FROM (SELECT ST_Transform(ST_SimplifyPreserveTopology(boundary, 0.002), 3857) AS g FROM states WHERE lgd_code = ? AND boundary IS NOT NULL) s
            ", [config('dcp.map.state_lgd_code')]);
            if (! $state) {
                return null;
            }

            $loc = in_array(app()->getLocale(), ['as', 'bn'], true) ? app()->getLocale() : 'en';
            $rows = DB::select("
                SELECT COALESCE(d.name_$loc, d.name_en) AS name,
                       ST_AsSVG(ST_Transform(ST_SimplifyPreserveTopology(d.boundary, 0.002), 3857), 0, 0) AS path,
                       COUNT(w.id) AS works, ROUND(AVG(w.physical_pct), 1) AS physical
                FROM districts d
                LEFT JOIN works w ON w.district_id = d.id AND w.deleted_at IS NULL AND w.status <> 'cancelled'
                WHERE d.boundary IS NOT NULL
                GROUP BY d.id, d.name_en, d.name_$loc, d.boundary
                ORDER BY d.name_en
            ");
            $pad = $state->w * 0.02;

            return [
                'viewBox' => sprintf('%d %d %d %d', $state->x0 - $pad, -$state->y1 - $pad, $state->w + 2 * $pad, $state->h + 2 * $pad),
                'state' => $state->path,
                'districts' => array_map(fn ($r) => [
                    'name' => $r->name,
                    'path' => $r->path,
                    'works' => $withFigures ? (int) $r->works : 0,
                    'physical' => $withFigures && $r->physical !== null ? (float) $r->physical : null,
                ], $rows),
            ];
        });
    }
}
