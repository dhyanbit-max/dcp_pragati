<?php

namespace App\Domain\Access;

use App\Models\User;
use App\Models\Work;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Restricts works (and records that belong to a work) to the authenticated user's jurisdiction.
 * No authenticated user (console, queued jobs) means no filter: jobs must scope explicitly.
 */
class JurisdictionScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            return;
        }

        if ($model instanceof Work) {
            $user->applyWorkScope($builder, $model->getTable());

            return;
        }

        // Child records: restrict by their work_id.
        if ($user->hasStateScope()) {
            return;
        }
        $builder->whereIn(
            $model->getTable().'.work_id',
            $user->applyWorkScope(Work::withoutGlobalScope(self::class)->select('works.id'))
        );
    }
}
