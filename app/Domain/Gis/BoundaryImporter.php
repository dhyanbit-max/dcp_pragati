<?php

namespace App\Domain\Gis;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Loads area boundaries from a GeoJSON FeatureCollection (EPSG:4326) onto existing districts,
 * co-districts or constituencies, matched on a feature property (docs/10 §2, docs/18 D-8).
 * Unmatched features are reported, never created: areas themselves come from the masters import.
 */
class BoundaryImporter
{
    /** level => [table, key column] */
    public const TARGETS = [
        'state' => ['states', 'lgd_code'],
        'district' => ['districts', 'lgd_code'],
        'co_district' => ['co_districts', 'lgd_code'],
        'constituency' => ['constituencies', 'ac_no'],
    ];

    /** @return array{updated: int, unmatched: list<string>, invalid: list<string>} */
    public function import(string $level, string $path, string $property, ?string $source = null): array
    {
        [$table, $key] = self::TARGETS[$level] ?? throw new RuntimeException("Unknown level $level");
        $data = json_decode((string) @file_get_contents($path), true);
        if (($data['type'] ?? null) !== 'FeatureCollection') {
            throw new RuntimeException('Expected a GeoJSON FeatureCollection.');
        }

        $out = ['updated' => 0, 'unmatched' => [], 'invalid' => []];
        DB::transaction(function () use ($data, $table, $key, $property, $source, &$out) {
            foreach ($data['features'] ?? [] as $i => $f) {
                $code = (string) ($f['properties'][$property] ?? '');
                $type = $f['geometry']['type'] ?? null;
                if ($code === '' || ! in_array($type, ['Polygon', 'MultiPolygon'], true)) {
                    $out['invalid'][] = "feature #$i";

                    continue;
                }
                $n = DB::update(
                    "UPDATE $table SET boundary = ST_Multi(ST_CollectionExtract(ST_MakeValid(ST_SetSRID(ST_GeomFromGeoJSON(?), 4326)), 3)), updated_at = NOW()"
                    .($table === 'states' ? ', boundary_source = ?' : '')." WHERE $key::text = ?",
                    $table === 'states' ? [json_encode($f['geometry']), $source, $code] : [json_encode($f['geometry']), $code]
                );
                $n ? $out['updated'] += $n : $out['unmatched'][] = $code;
            }
        });

        return $out;
    }
}
