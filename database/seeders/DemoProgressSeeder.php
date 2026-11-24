<?php

namespace Database\Seeders;

use App\Domain\Progress\ApprovalService;
use App\Domain\Progress\PhotoService;
use App\Domain\Progress\ProgressInput;
use App\Domain\Progress\ProgressSubmissionService;
use App\Models\ProgressUpdate;
use App\Models\User;
use App\Models\Work;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Demo progress history, run through the real submission + approval services so every
 * record has passed the rules engine. Gives the dashboard one work in each state:
 * healthy, stale, spend-ahead, delayed + stalled, plus clean and flagged pending updates.
 */
class DemoProgressSeeder extends Seeder
{
    public function run(PhotoService $photos, ProgressSubmissionService $submit, ApprovalService $approvals): void
    {
        $works = Work::withoutGlobalScopes()->with(['stageTemplate', 'district'])->where('code', 'like', 'DEMO-%')->orderBy('code')->get()->values();
        if ($works->count() < 5 || ProgressUpdate::withoutGlobalScopes()->whereIn('work_id', $works->pluck('id'))->exists()) {
            return;
        }

        $dpcA = User::where('mobile', '9000000002')->first();
        $dpcB = User::where('mobile', '9000000003')->first();

        // [work index, days ago, stage seq, physical %, spent ₹ lakh, approve?, far photo?]
        $plan = [
            [0, 80, 2, 15, 6, true, false], [0, 40, 3, 35, 12, true, false], [0, 5, 3, 50, 18, true, false],
            [1, 50, 2, 20, 5, true, false], [1, 1, 2, 28, 8, false, false],
            [2, 30, 2, 10, 19, true, false], [2, 2, 2, 14, 19.5, false, true],
            [3, 70, 2, 12, 4, true, false],
        ];

        foreach ($plan as [$i, $daysAgo, $seq, $pct, $lakh, $approve, $far]) {
            /** @var Work $work */
            $work = $works[$i];
            $field = User::find($work->assigned_field_user_id);
            $at = Carbon::now()->subDays($daysAgo)->setTime(11, 0);
            $site = DB::selectOne('SELECT ST_Y(site) lat, ST_X(site) lng FROM works WHERE id = ?', [$work->id]);

            $uuids = [];
            foreach ([0, 1] as $k) {
                $lat = $site->lat + ($far && $k === 0 ? 0.01 : 0.0002 * ($k + 1));
                $uuids[] = $photos->store($field, $work, $this->demoJpeg($work->code, $at), [
                    'client_uuid' => (string) Str::uuid(),
                    'lat' => $lat, 'lng' => $site->lng + 0.0002, 'accuracy_m' => 6 + $k,
                    'taken_at' => $at->copy()->subMinutes(10 - $k)->toIso8601String(),
                ])->client_uuid;
            }

            $result = $submit->submit($field, new ProgressInput(
                clientUuid: (string) Str::uuid(),
                workId: $work->id,
                stageItemId: $work->stageTemplate->items()->where('seq', $seq)->value('id'),
                physicalPct: $pct,
                spentCumulative: (int) round($lakh * 1_00_000 * 100),
                capturedAt: $at,
                remarks: $approve ? null : 'Demo pending update',
                photoUuids: $uuids,
                formSeconds: random_int(70, 170),
            ));

            $update = $result->update;
            if ($approve && $update->status === ProgressUpdate::STATUS_PENDING) {
                $approvals->approve($update, $work->district->lgd_code === 'DEMO1' ? $dpcA : $dpcB,
                    $update->is_flagged ? 'Checked on site (demo)' : null);
            }
        }

        // One delayed + stalled work: target date passed, last update 70 days ago.
        $works[3]->forceFill(['target_completion_date' => now()->subDays(20)->toDateString()])->save();
    }

    private function demoJpeg(string $code, Carbon $at): UploadedFile
    {
        $img = imagecreatetruecolor(640, 480);
        imagefill($img, 0, 0, imagecolorallocate($img, random_int(90, 140), random_int(110, 150), random_int(80, 110)));
        $white = imagecolorallocate($img, 255, 255, 255);
        imagestring($img, 5, 20, 20, 'DEMO PHOTO', $white);
        imagestring($img, 4, 20, 440, $code.'  '.$at->format('Y-m-d H:i'), $white);
        $path = tempnam(sys_get_temp_dir(), 'dcp').'.jpg';
        imagejpeg($img, $path, 70);
        imagedestroy($img);

        return new UploadedFile($path, basename($path), 'image/jpeg', null, true);
    }
}
