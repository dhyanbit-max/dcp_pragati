<?php

use App\Domain\Access\Permission;
use App\Http\Controllers\Web\GrievancePhotoController;
use App\Http\Controllers\Web\ImportTemplateController;
use App\Http\Controllers\Web\LandingController;
use App\Http\Controllers\Web\LoginController;
use App\Http\Controllers\Web\MapDataController;
use App\Http\Controllers\Web\PasswordSetupController;
use App\Http\Controllers\Web\PhotoController;
use App\Http\Controllers\Web\ReportExportController;
use App\Livewire\Admin\Import;
use App\Livewire\Alerts\Inbox;
use App\Livewire\Approvals\Queue;
use App\Livewire\Dashboard\Overview;
use App\Livewire\Grievances\Index as GrievancesIndex;
use App\Livewire\Grievances\Show as GrievancesShow;
use App\Livewire\Map\WorksMap;
use App\Livewire\Progress\BulkEditor;
use App\Livewire\Progress\EntryForm;
use App\Livewire\PublicSite\GrievanceForm;
use App\Livewire\PublicSite\GrievanceTrack;
use App\Livewire\Reports\AqDesk;
use App\Livewire\Reports\Runner as ReportsRunner;
use App\Livewire\Works\Index as WorksIndex;
use App\Livewire\Works\Show as WorksShow;
use Illuminate\Support\Facades\Route;

// Public pages (docs/04 §4, docs/20). Feature-flagged; the landing page itself is always there.
Route::get('/', LandingController::class)->name('home');
Route::view('manual', 'public.manual')->name('manual');
Route::middleware(['feature:grievances', 'throttle:60,1'])->group(function () {
    Route::get('grievances/new', GrievanceForm::class)->name('grievances.create');
    Route::get('grievances/track', GrievanceTrack::class)->name('grievances.track');
});

Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'show'])->name('login');
    Route::post('login', [LoginController::class, 'attempt'])->middleware('throttle:10,1');
    Route::get('login/otp', [LoginController::class, 'showOtp'])->name('login.otp');
    Route::post('login/otp', [LoginController::class, 'verifyOtp'])->middleware('throttle:10,1');
    Route::get('password/setup', [PasswordSetupController::class, 'show'])->name('password.setup');
    Route::post('password/setup', [PasswordSetupController::class, 'sendCode'])->middleware('throttle:5,1');
    Route::get('password/setup/verify', [PasswordSetupController::class, 'showVerify'])->name('password.setup.verify');
    Route::post('password/setup/verify', [PasswordSetupController::class, 'update'])->middleware('throttle:10,1');
});

Route::post('locale', [LoginController::class, 'setLocale'])->name('locale');

Route::middleware('auth')->group(function () {
    Route::post('logout', [LoginController::class, 'logout'])->name('logout');

    Route::middleware('can:'.Permission::VIEW_DASHBOARD)->group(function () {
        Route::get('dashboard', Overview::class)->name('dashboard');
        Route::get('works', WorksIndex::class)->name('works.index');
        Route::get('works/{work}', WorksShow::class)->name('works.show');
    });

    Route::get('works/{work}/progress', EntryForm::class)->name('works.progress')->middleware('can:'.Permission::SUBMIT_PROGRESS);
    Route::get('bulk-edit', BulkEditor::class)->name('bulk-edit')->middleware('can:'.Permission::BULK_EDIT);

    Route::middleware('can:'.Permission::MANAGE_MASTERS)->group(function () {
        Route::get('import', Import::class)->name('import');
        Route::get('import/template', ImportTemplateController::class)->name('import.template');
    });

    Route::middleware('can:'.Permission::VIEW_MAP)->group(function () {
        Route::get('map', WorksMap::class)->name('map');
        Route::get('map/works.geojson', [MapDataController::class, 'works'])->name('map.works');
        Route::get('map/state.geojson', [MapDataController::class, 'state'])->name('map.state');
        Route::get('map/boundaries/{level}.geojson', [MapDataController::class, 'boundaries'])->name('map.boundaries');
        Route::get('map/borders/{level}.geojson', [MapDataController::class, 'borders'])->name('map.borders');
        Route::get('map/works/{work}.json', [MapDataController::class, 'work'])->name('map.work');
    });

    Route::middleware('can:'.Permission::RUN_REPORTS)->group(function () {
        Route::get('reports', ReportsRunner::class)->name('reports');
        Route::get('reports/{code}/export/{format}', [ReportExportController::class, 'report'])
            ->whereIn('format', ['xlsx', 'pdf'])->name('reports.export');
    });
    Route::middleware('can:'.Permission::AQ_DESK)->group(function () {
        Route::get('aq', AqDesk::class)->name('aq');
        Route::get('aq/{aq}/export/{format}', [ReportExportController::class, 'aq'])
            ->whereIn('format', ['xlsx', 'pdf'])->name('aq.export');
    });

    Route::middleware(['feature:grievances', 'can:'.Permission::HANDLE_GRIEVANCES])->group(function () {
        Route::get('grievances', GrievancesIndex::class)->name('grievances.index');
        Route::get('grievances/{grievance}', GrievancesShow::class)->whereNumber('grievance')->name('grievances.show');
        Route::get('grievances/{grievance}/photo', GrievancePhotoController::class)->whereNumber('grievance')->name('grievances.photo');
    });

    Route::get('alerts', Inbox::class)->name('alerts')->middleware('can:'.Permission::HANDLE_ALERTS);
    Route::get('approvals', Queue::class)->name('approvals')->middleware('can:'.Permission::APPROVE_PROGRESS);
    Route::get('photos/{photo}', [PhotoController::class, 'show'])->name('photos.show');
});
