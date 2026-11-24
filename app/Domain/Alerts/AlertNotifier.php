<?php

namespace App\Domain\Alerts;

use App\Domain\Access\Role;
use App\Jobs\SendAlertNotification;
use App\Models\Alert;
use App\Models\AlertNotification;
use App\Models\AlertPolicy;
use App\Models\User;
use App\Models\Work;
use App\Models\WorkAssignment;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Works out who hears about an alert at a given level, and queues one message per
 * recipient per channel. The web/app inbox is the in-app channel (no message row).
 * Rules (docs/08 §3): quiet hours for SMS/WhatsApp, one message per person per alert
 * per channel per `rate_limit_hours`, WhatsApp only for users who opted in.
 */
class AlertNotifier
{
    public function notify(Alert $alert, int $level): int
    {
        $policy = AlertPolicy::where('code', $alert->alert_code)->first();
        $channels = array_values(array_diff($policy?->channels ?? ['in_app'], ['in_app']));
        if ($channels === []) {
            return 0;
        }

        $work = Work::withoutGlobalScopes()->find($alert->work_id);
        if (! $work) {
            return 0;
        }

        $queued = 0;
        foreach ($this->recipients($alert, $work, $level) as $user) {
            foreach ($channels as $channel) {
                $to = match ($channel) {
                    'sms' => $user->mobile,
                    'whatsapp' => $user->whatsapp_opt_in ? $user->mobile : null,
                    'email' => $user->email,
                    default => null,
                };
                if (! $to || $this->recentlySent($alert, $user, $channel)) {
                    continue;
                }

                $scheduled = in_array($channel, ['sms', 'whatsapp'], true) ? $this->afterQuietHours(now()) : null;
                $n = AlertNotification::create([
                    'alert_id' => $alert->id,
                    'level' => $level,
                    'channel' => $channel,
                    'recipient_user_id' => $user->id,
                    'to' => $to,
                    'template_id' => "{$alert->alert_code}.message",
                    'body' => $this->message($alert, $work, $user->preferred_locale ?: 'en'),
                    'status' => 'queued',
                    'scheduled_for' => $scheduled,
                ]);

                $job = SendAlertNotification::dispatch($n->id)->afterCommit();
                if ($scheduled) {
                    $job->delay($scheduled);
                }
                $queued++;
            }
        }

        return $queued;
    }

    /** @return Collection<int, User> */
    public function recipients(Alert $alert, Work $work, int $level): Collection
    {
        $users = match ($level) {
            0 => $this->owners($alert->alert_code, $work),
            1 => $this->districtUsers(Role::DPC, $work->district_id),
            2 => $this->districtUsers(Role::DISTRICT_COLLECTOR, $work->district_id),
            default => User::role(Role::STATE_ADMIN)->where('is_active', true)->get(),
        };

        // Nobody owns it at level 0 (e.g. no field officer assigned yet): the DPC hears instead.
        if ($level === 0 && $users->isEmpty()) {
            $users = $this->districtUsers(Role::DPC, $work->district_id);
        }

        return $users->where('is_active', true)->unique('id')->values();
    }

    private function owners(string $code, Work $work): Collection
    {
        return match ($code) {
            AlertCode::NO_UPDATE, AlertCode::MISSED_MILESTONE => $this->fieldUsers($work)
                ->merge($code === AlertCode::MISSED_MILESTONE ? $this->agencyUsers($work) : collect()),
            AlertCode::UNSPENT_RELEASE => $this->agencyUsers($work),
            AlertCode::SPEND_MISMATCH => $this->agencyUsers($work)->merge($this->districtUsers(Role::DPC, $work->district_id)),
            AlertCode::APPROVAL_OVERDUE => $this->districtUsers(Role::DPC, $work->district_id),
            default => collect(),
        };
    }

    private function fieldUsers(Work $work): Collection
    {
        $ids = WorkAssignment::where('work_id', $work->id)->where('role', 'field')->pluck('user_id')
            ->push($work->assigned_field_user_id)->filter()->unique();

        return User::whereIn('id', $ids)->get();
    }

    private function agencyUsers(Work $work): Collection
    {
        return User::role(Role::AGENCY_USER)->whereHas('jurisdictions', fn ($q) => $q->where('level', 'agency')
            ->where('implementing_agency_id', $work->implementing_agency_id)
            ->where(fn ($q) => $q->whereNull('district_id')->orWhere('district_id', $work->district_id)))->get();
    }

    private function districtUsers(string $role, int $districtId): Collection
    {
        return User::role($role)->whereHas('jurisdictions', fn ($q) => $q->where('level', 'district')->where('district_id', $districtId))->get();
    }

    private function recentlySent(Alert $alert, User $user, string $channel): bool
    {
        return AlertNotification::where('alert_id', $alert->id)
            ->where('recipient_user_id', $user->id)
            ->where('channel', $channel)
            ->where('created_at', '>', now()->subHours((int) config('dcp.alerts.rate_limit_hours', 24)))
            ->exists();
    }

    /** null if `at` is outside quiet hours, else the next end of quiet hours (08:00 IST). */
    public function afterQuietHours(Carbon $at): ?Carbon
    {
        $local = $at->copy()->setTimezone(config('app.timezone'));
        $start = (int) config('dcp.alerts.quiet_start_hour', 21);
        $end = (int) config('dcp.alerts.quiet_end_hour', 8);
        $h = $local->hour;
        if ($h >= $end && $h < $start) {
            return null;
        }
        $release = $local->copy()->setTime($end, 0);
        if ($h >= $start) {
            $release->addDay();
        }

        return $release;
    }

    public function message(Alert $alert, Work $work, string $locale): string
    {
        $d = $alert->details_json ?? [];
        $name = ($locale === 'as' && $work->name_as) ? $work->name_as : $work->name_en;

        return __("alert_types.{$alert->alert_code}.message", [
            'code' => $work->code,
            'name' => $name,
            'days' => $d['days'] ?? '',
            'stage' => $d['stage'] ?? '',
            'due' => isset($d['due']) ? Carbon::parse($d['due'])->format('d M Y') : '',
            'utilisation' => $d['utilisation_pct'] ?? '',
            'released' => isset($d['released']) ? Money::short((int) $d['released']) : '',
            'financial' => $d['financial_pct'] ?? '',
            'physical' => $d['physical_pct'] ?? '',
            'pending' => $d['pending'] ?? '',
        ], $locale);
    }
}
