<?php

use App\Domain\Alerts\AlertEvaluator;
use App\Domain\Alerts\DigestSender;
use App\Domain\Alerts\Escalator;
use App\Domain\Gis\BoundaryImporter;
use App\Domain\Grievances\GrievanceService;
use App\Domain\Reports\WeeklyReports;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

// Alerts (docs/08 §4)
Artisan::command('alerts:evaluate', function (AlertEvaluator $evaluator) {
    $r = $evaluator->evaluate();
    $this->info("Alerts opened: {$r['opened']}, auto-closed: {$r['closed']}");
})->purpose('Open and auto-close alerts A01–A05 for every work');

Artisan::command('alerts:escalate', function (Escalator $escalator) {
    $r = $escalator->run();
    $this->info("Alerts escalated: {$r['escalated']}, re-opened after acknowledgement pause: {$r['reopened']}");
})->purpose('Escalate unhandled alerts to the DPC, DC and DCP Division');

Artisan::command('alerts:digest', function (DigestSender $digest) {
    $this->info('Digests sent: '.$digest->send());
})->purpose('Email the daily alert digest to DPCs and DCs');

Artisan::command('reports:weekly', function (WeeklyReports $weekly) {
    $this->info('Weekly report emails sent: '.$weekly->send());
})->purpose('Email MIS-01 and MIS-05 to DPCs, DCs and state admins');

// Grievances (docs/20): clear citizen name and mobile once the retention period after closing has passed.
Artisan::command('grievances:purge', function (GrievanceService $grievances) {
    $this->info('Grievances with personal data cleared: '.$grievances->purgePersonalData());
})->purpose('Clear citizen contact details from grievances closed longer than the retention period');

// GIS (docs/10): load boundaries from GeoJSON, e.g. LGD or Department shapefiles converted with ogr2ogr.
Artisan::command('geo:import-boundaries {level : state|district|co_district|constituency} {file : GeoJSON FeatureCollection in EPSG:4326} {--property= : Feature property holding the LGD code / AC number} {--source= : Where the outline came from (recorded for the state)}', function (BoundaryImporter $importer) {
    $level = $this->argument('level');
    $property = $this->option('property') ?: ($level === 'constituency' ? 'ac_no' : 'lgd_code');
    $r = $importer->import($level, $this->argument('file'), $property, $this->option('source'));
    $this->info("Boundaries updated: {$r['updated']}");
    if ($r['unmatched']) {
        $this->warn('No matching area for: '.implode(', ', array_slice($r['unmatched'], 0, 50)));
    }
    if ($r['invalid']) {
        $this->warn('Skipped (no code or not a polygon): '.implode(', ', array_slice($r['invalid'], 0, 50)));
    }
})->purpose('Load area boundaries from a GeoJSON file');

Schedule::command('alerts:evaluate')->dailyAt('06:00')->timezone('Asia/Kolkata')->withoutOverlapping()->onOneServer();
Schedule::command('alerts:escalate')->hourly()->withoutOverlapping()->onOneServer();
Schedule::command('alerts:digest')->dailyAt('08:00')->timezone('Asia/Kolkata')->onOneServer();
Schedule::command('reports:weekly')->weeklyOn(1, '09:00')->timezone('Asia/Kolkata')->onOneServer();
Schedule::command('grievances:purge')->dailyAt('02:30')->timezone('Asia/Kolkata')->onOneServer();
