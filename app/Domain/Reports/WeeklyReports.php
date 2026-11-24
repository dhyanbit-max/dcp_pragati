<?php

namespace App\Domain\Reports;

use App\Domain\Access\Role;
use App\Domain\Reports\Export\XlsxExporter;
use App\Mail\WeeklyReports as WeeklyReportsMail;
use App\Models\ReportRun;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;

/**
 * Monday 09:00 (docs/09 §5): district abstract + freshness as Excel to each DPC and DC (their district),
 * and to state admins (state-wide). Each report runs as the recipient, so the jurisdiction scope applies.
 */
class WeeklyReports
{
    public const REPORTS = ['MIS-01', 'MIS-05'];

    public function __construct(
        private readonly ReportRunner $runner,
        private readonly XlsxExporter $xlsx,
    ) {}

    public function send(): int
    {
        $sent = 0;
        $users = User::role([Role::DPC, Role::DISTRICT_COLLECTOR, Role::STATE_ADMIN])
            ->where('is_active', true)->whereNotNull('email')->get();

        foreach ($users as $user) {
            Auth::setUser($user); // reports are scoped to whoever runs them
            app()->setLocale($user->preferred_locale ?: 'en');
            $files = [];
            foreach (self::REPORTS as $code) {
                $result = $this->runner->run($code, [], [], $user, 'xlsx');
                $path = tempnam(sys_get_temp_dir(), 'dcp-weekly').'.xlsx';
                $this->xlsx->write($result, $path);
                $files[$result->fileName('xlsx')] = $path;
            }
            ReportRun::where('requested_by', $user->id)->latest('id')->limit(count(self::REPORTS))->update(['scheduled' => true]);

            Mail::to($user->email)->send(new WeeklyReportsMail($user, $files));
            array_map('unlink', $files);
            $sent++;
        }
        Auth::forgetUser();
        app()->setLocale(config('app.locale'));

        return $sent;
    }
}
