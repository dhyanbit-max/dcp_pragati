<?php

namespace App\Domain\Grievances;

use App\Domain\Access\Permission;
use App\Jobs\SendCitizenSms;
use App\Models\CoDistrict;
use App\Models\Grievance;
use App\Models\GrievanceEvent;
use App\Models\User;
use App\Models\Work;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Filing and handling citizen grievances (docs/20). Every step writes a timeline event and the audit log. */
class GrievanceService
{
    public const DISK = 'local';

    /**
     * Files a grievance whose mobile has just been verified by OTP.
     *
     * @param  array{district_id:int, co_district_id:?int, category:string, subject:string, description:string,
     *     location_text:?string, lat:?float, lng:?float, work_reference:?string, citizen_name:?string,
     *     citizen_mobile:string, citizen_locale:string}  $data
     */
    public function submit(array $data, ?UploadedFile $photo = null): Grievance
    {
        if (! in_array($data['category'], config('dcp.grievances.categories'), true)) {
            throw new InvalidArgumentException('Unknown category.');
        }
        if (! empty($data['co_district_id']) && ! CoDistrict::whereKey($data['co_district_id'])->where('district_id', $data['district_id'])->exists()) {
            throw new InvalidArgumentException('Co-district is not in the district.');
        }

        $grievance = DB::transaction(function () use ($data, $photo) {
            $now = now();
            $g = Grievance::withoutGlobalScopes()->create([
                ...$data,
                'ref_no' => 'TMP-'.Str::random(12),
                'photo_path' => $photo ? $photo->storeAs('grievances/'.$now->format('Y/m'), Str::uuid().'.'.($photo->extension() ?: 'jpg'), self::DISK) : null,
                'status' => Grievance::SUBMITTED,
                'mobile_verified_at' => $now,
                'consented_at' => $now,
                'acknowledge_by' => $now->copy()->addDays((int) config('dcp.grievances.acknowledge_days', 3)),
                'resolve_by' => $now->copy()->addDays((int) config('dcp.grievances.resolve_days', 15)),
            ]);
            $g->update(['ref_no' => sprintf('GRV-%s-%06d', $now->format('y'), $g->id)]);
            $this->event($g, null, 'submitted', null, Grievance::SUBMITTED, null, true);

            return $g;
        });

        $this->sms($grievance, __('DCP Pragati: your grievance :ref is registered. Track it at :url', [
            'ref' => $grievance->ref_no, 'url' => route('grievances.track'),
        ], $grievance->citizen_locale));

        return $grievance;
    }

    /** Moves a grievance on. A note is required to resolve or reject; it is shown to the citizen. */
    public function transition(Grievance $g, User $by, string $to, ?string $note = null): Grievance
    {
        $this->authorise($by);
        if (! in_array($to, Grievance::TRANSITIONS[$g->status] ?? [], true)) {
            throw new InvalidArgumentException("Cannot move a {$g->status} grievance to $to.");
        }
        $final = in_array($to, [Grievance::RESOLVED, Grievance::REJECTED], true);
        if ($final && mb_strlen(trim((string) $note)) < 10) {
            throw new InvalidArgumentException('Say what was done (at least 10 characters).');
        }

        DB::transaction(function () use ($g, $by, $to, $note, $final) {
            $from = $g->status;
            $g->fill([
                'status' => $to,
                'acknowledged_at' => $g->acknowledged_at ?? now(),
                'assigned_to' => $g->assigned_to ?? $by->id,
            ]);
            if ($final) {
                $g->fill(['closed_at' => now(), 'resolution_note' => trim($note)]);
            }
            $g->save();
            $this->event($g, $by, 'status', $from, $to, $note ? trim($note) : null, true);
        });

        $this->sms($g, __('DCP Pragati: grievance :ref is now ":status".', [
            'ref' => $g->ref_no, 'status' => self::statusLabel($to, $g->citizen_locale),
        ], $g->citizen_locale).($final && $note ? ' '.mb_substr(trim($note), 0, 120) : ''));

        return $g;
    }

    public function note(Grievance $g, User $by, string $note, bool $public): GrievanceEvent
    {
        $this->authorise($by);
        $note = trim($note);
        if (mb_strlen($note) < 3) {
            throw new InvalidArgumentException('Write a note.');
        }

        return $this->event($g, $by, 'note', null, null, $note, $public);
    }

    public function assign(Grievance $g, User $by, User $to): void
    {
        $this->authorise($by);
        $this->authorise($to);
        $g->update(['assigned_to' => $to->id]);
        $this->event($g, $by, 'assigned', null, null, $to->name, false);
    }

    /** Links the work the grievance is about. It must be a work in the grievance's district. */
    public function linkWork(Grievance $g, User $by, ?Work $work): void
    {
        $this->authorise($by);
        if ($work && $work->district_id !== $g->district_id) {
            throw new InvalidArgumentException('That work is in another district.');
        }
        $g->update(['work_id' => $work?->id]);
        $this->event($g, $by, 'work_linked', null, null, $work?->code, false);
    }

    /** Clears citizen name and mobile on grievances closed longer than the retention period. Returns how many. */
    public function purgePersonalData(): int
    {
        $before = now()->subDays((int) config('dcp.grievances.retention_days', 1095));

        return Grievance::withoutGlobalScopes()
            ->whereNotNull('closed_at')->where('closed_at', '<', $before)->whereNull('personal_data_purged_at')
            ->update(['citizen_name' => null, 'citizen_mobile' => null, 'personal_data_purged_at' => now()]);
    }

    public function photoPath(Grievance $g): string
    {
        return Storage::disk(self::DISK)->path($g->photo_path);
    }

    public static function statusLabel(string $status, ?string $locale = null): string
    {
        return match ($status) {
            Grievance::SUBMITTED => __('Received', [], $locale),
            Grievance::ACKNOWLEDGED => __('Acknowledged', [], $locale),
            Grievance::IN_PROGRESS => __('Being looked into', [], $locale),
            Grievance::RESOLVED => __('Resolved', [], $locale),
            Grievance::REJECTED => __('Closed without action', [], $locale),
            default => $status,
        };
    }

    public static function categoryLabel(string $category): string
    {
        return match ($category) {
            'quality' => __('Poor quality of work'),
            'delay' => __('Work is delayed'),
            'not_started' => __('Work not started'),
            'incomplete' => __('Left incomplete'),
            'safety' => __('Safety hazard at the site'),
            'wrong_location' => __('Work at a different place than announced'),
            default => __('Something else'),
        };
    }

    private function event(Grievance $g, ?User $by, string $type, ?string $from, ?string $to, ?string $note, bool $public): GrievanceEvent
    {
        return GrievanceEvent::create([
            'grievance_id' => $g->id, 'user_id' => $by?->id, 'type' => $type,
            'from_status' => $from, 'to_status' => $to, 'note' => $note, 'is_public' => $public,
        ]);
    }

    private function sms(Grievance $g, string $text): void
    {
        if ($g->citizen_mobile) {
            SendCitizenSms::dispatch($g->citizen_mobile, $text)->afterCommit();
        }
    }

    private function authorise(User $user): void
    {
        if (! $user->can(Permission::HANDLE_GRIEVANCES)) {
            throw new InvalidArgumentException('This user cannot handle grievances.');
        }
    }
}
