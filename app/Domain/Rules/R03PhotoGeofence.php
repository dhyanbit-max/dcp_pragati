<?php

namespace App\Domain\Rules;

final class R03PhotoGeofence implements Rule
{
    public function code(): string
    {
        return 'R03';
    }

    public function defaults(): array
    {
        return ['severity' => 'flag', 'params' => []];
    }

    public function evaluate(Context $ctx, array $params): ?Failure
    {
        if (! $ctx->work->hasSite) {
            return null; // first geotagged update proposes the site (docs/05 §3 notes)
        }
        $radius = (int) ($params['radius_m'] ?? $ctx->work->siteRadiusM);
        $outside = [];
        foreach ($ctx->photos as $i => $p) {
            if (! $p->hasLocation || $p->distanceM === null || $p->distanceM > $radius) {
                $outside[] = ['photo' => $i, 'distance_m' => $p->distanceM];
            }
        }

        return $outside ? new Failure('rules.R03', ['radius_m' => $radius, 'photos' => $outside]) : null;
    }
}
