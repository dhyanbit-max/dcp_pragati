<?php

namespace App\Domain\Alerts;

use App\Domain\Access\JurisdictionScope;
use App\Domain\Access\Role;
use App\Mail\AlertDigest;
use App\Models\Alert;
use App\Models\ProgressUpdate;
use App\Models\User;
use App\Models\Work;
use Illuminate\Support\Facades\Mail;

/** Daily digest email to DPCs and DCs with an email address (docs/08 §3). Sends nothing if there is nothing to say. */
class DigestSender
{
    public function send(): int
    {
        $sent = 0;
        $users = User::role([Role::DPC, Role::DISTRICT_COLLECTOR])->where('is_active', true)->whereNotNull('email')->get();

        foreach ($users as $user) {
            $districts = $user->jurisdictions()->where('level', 'district')->pluck('district_id');
            if ($districts->isEmpty()) {
                continue;
            }
            $inDistricts = fn ($q) => $q->whereIn('work_id', Work::withoutGlobalScopes()->whereIn('district_id', $districts)->select('id'));
            $myLevel = $user->hasRole(Role::DISTRICT_COLLECTOR) ? 2 : 1;

            $new = Alert::withoutGlobalScope(JurisdictionScope::class)->with('work:id,code')
                ->where($inDistricts)->where('opened_at', '>', now()->subDay())->get();
            $escalated = Alert::withoutGlobalScope(JurisdictionScope::class)->with('work:id,code')
                ->where($inDistricts)->where('status', Alert::STATUS_OPEN)->where('level', $myLevel)->get();
            $pending = ProgressUpdate::withoutGlobalScope(JurisdictionScope::class)->pending()->where($inDistricts)->count();

            if ($new->isEmpty() && $escalated->isEmpty() && $pending === 0) {
                continue;
            }

            Mail::to($user->email)->locale($user->preferred_locale ?: 'en')->send(new AlertDigest($user, $new, $escalated, $pending));
            $sent++;
        }

        return $sent;
    }
}
