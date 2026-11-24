<?php

use App\Models\Alert;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** App and database must agree on the time zone, or every timestamptz shifts by 5h30 (see config/database.php). */
it('stores and reads timestamps as the same instant', function () {
    [$work] = workWithFieldUser();
    $instant = Carbon::parse('2026-12-01T10:00:00+05:30');

    $alert = Alert::withoutGlobalScopes()->create(['work_id' => $work->id, 'alert_code' => 'A01', 'opened_at' => $instant]);

    expect($alert->fresh()->opened_at->equalTo($instant))->toBeTrue()
        ->and(DB::selectOne('SELECT EXTRACT(EPOCH FROM opened_at)::bigint AS e FROM alerts WHERE id = ?', [$alert->id])->e)
        ->toBe($instant->getTimestamp());
});

it('runs in India Standard Time', function () {
    expect(config('app.timezone'))->toBe('Asia/Kolkata')
        ->and(DB::selectOne('SHOW TIMEZONE')->TimeZone)->toBe('Asia/Kolkata');
});
