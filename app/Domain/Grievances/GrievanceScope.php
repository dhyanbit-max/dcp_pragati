<?php

namespace App\Domain\Grievances;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Grievances by area: state roles see all, district jurisdictions their district, co-district officers their
 * co-district. Anyone else (agency, field) sees none. No authenticated user (citizen pages, jobs): no filter,
 * so public code must look grievances up by reference and mobile only.
 */
class GrievanceScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $user = Auth::user();
        if (! $user instanceof User || $user->hasStateScope()) {
            return;
        }

        $t = $model->getTable();
        $jurisdictions = $user->loadMissing('jurisdictions')->jurisdictions
            ->filter(fn ($j) => in_array($j->level, ['state', 'district', 'co_district'], true));
        if ($jurisdictions->isEmpty()) {
            $builder->whereRaw('false');

            return;
        }

        $builder->where(function (Builder $q) use ($jurisdictions, $t) {
            foreach ($jurisdictions as $j) {
                match ($j->level) {
                    'state' => $q->orWhereRaw('true'),
                    'district' => $q->orWhere("$t.district_id", $j->district_id),
                    'co_district' => $q->orWhere("$t.co_district_id", $j->co_district_id),
                };
            }
        });
    }
}
