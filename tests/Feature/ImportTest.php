<?php

use App\Domain\Access\Permission;
use App\Domain\Access\Role;
use App\Domain\Import\TemplateWriter;
use App\Domain\Import\WorkbookImporter;
use App\Domain\Import\WorkbookReader;
use App\Livewire\Admin\Import;
use App\Models\CoDistrict;
use App\Models\District;
use App\Models\FundRelease;
use App\Models\ImplementingAgency;
use App\Models\StageTemplate;
use App\Models\SyncBatch;
use App\Models\User;
use App\Models\Work;
use Database\Factories\WorkFactory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role as SpatieRole;

/** Write an .xlsx with the given sheets: ['Works' => [[headers...], [row...], ...]]. Returns its path. */
function workbook(array $sheets): string
{
    $path = tempnam(sys_get_temp_dir(), 'dcp-test').'.xlsx';
    $w = new Writer;
    $w->openToFile($path);
    $first = true;
    foreach ($sheets as $name => $rows) {
        $sheet = $first ? $w->getCurrentSheet() : $w->addNewSheetAndMakeItCurrent();
        $sheet->setName($name);
        $first = false;
        foreach ($rows as $r) {
            $w->addRow(Row::fromValues($r));
        }
    }
    $w->close();

    return $path;
}

function importFile(string $path, $user, bool $commit = true)
{
    $sheets = app(WorkbookReader::class)->read($path, basename($path));

    return app(WorkbookImporter::class)->run($sheets, $user, $commit, basename($path));
}

const WORK_HEADERS = ['code', 'erupantor_id', 'name_en', 'district_lgd_code', 'co_district_lgd_code', 'scheme_type_code', 'scheme_type_name',
    'fund_source_code', 'fund_source_name', 'financial_year', 'agency_code', 'agency_name', 'stage_template_code',
    'sanctioned_amount_rs', 'sanction_date', 'site_lat', 'site_lng'];

function workRow(array $o = []): array
{
    $d = ['W-1', 'ER-1', 'Road A to B', 'L1', '', 'ROAD', 'Road', 'MLA', 'MLA fund', '2026-27', 'PWD', 'PWD div',
        'T_TEMPLATE', '1500000', '01-05-2026', '26.15', '91.74'];
    foreach ($o as $i => $v) {
        $d[array_search($i, WORK_HEADERS, true)] = $v;
    }

    return $d;
}

beforeEach(function () {
    WorkFactory::template(); // stage template T_TEMPLATE
    $this->state = userWithRole(Role::STATE_ADMIN);
});

it('imports the downloadable template example rows end to end', function () {
    $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
    app(TemplateWriter::class)->write($path);
    // The template example points at stage template DEMO_ROAD.
    StageTemplate::create(['code' => 'DEMO_ROAD', 'name_en' => 'Road'])->items()->create(['seq' => 1, 'name_en' => 'Not started', 'min_pct' => 0, 'max_pct' => 0]);

    $report = importFile($path, $this->state);

    expect($report->issues)->toBe([])->and($report->failedRows())->toBe(0)
        ->and(District::where('lgd_code', 'EX01')->exists())->toBeTrue()
        ->and(CoDistrict::where('lgd_code', 'EX01C')->exists())->toBeTrue();
    $work = Work::withoutGlobalScopes()->where('erupantor_id', 'ER-12345')->first();
    expect($work->sanctioned_amount)->toBe(50_00_000_00)
        ->and($work->released_total)->toBe(20_00_000_00)
        ->and($work->hasSite())->toBeTrue()
        ->and($work->assignedFieldUser?->mobile)->toBe('9876500000');
});

it('changes nothing on preview', function () {
    District::create(['lgd_code' => 'L1', 'name_en' => 'One']);
    $report = importFile(workbook(['Works' => [WORK_HEADERS, workRow()]]), $this->state, commit: false);

    expect($report->counts['Works']['created'])->toBe(1)
        ->and(Work::withoutGlobalScopes()->count())->toBe(0)
        ->and(SyncBatch::count())->toBe(0);
});

it('matches on e-Rupantor ID when re-imported, and records a batch', function () {
    District::create(['lgd_code' => 'L1', 'name_en' => 'One']);
    importFile(workbook(['Works' => [WORK_HEADERS, workRow()]]), $this->state);
    $again = importFile(workbook(['Works' => [WORK_HEADERS, workRow(['name_en' => 'Road A to B (renamed)'])]]), $this->state);

    expect(Work::withoutGlobalScopes()->count())->toBe(1)
        ->and($again->counts['Works']['updated'])->toBe(1)
        ->and(Work::withoutGlobalScopes()->first()->name_en)->toBe('Road A to B (renamed)')
        ->and(SyncBatch::count())->toBe(2);
});

it('reports bad rows and imports the rest', function () {
    District::create(['lgd_code' => 'L1', 'name_en' => 'One']);
    $report = importFile(workbook(['Works' => [
        WORK_HEADERS,
        workRow(),
        workRow(['code' => 'W-2', 'erupantor_id' => 'ER-2', 'district_lgd_code' => 'NOPE']),
        workRow(['code' => 'W-3', 'erupantor_id' => 'ER-3', 'site_lat' => '91.74', 'site_lng' => '26.15']), // swapped
        workRow(['code' => 'W-4', 'erupantor_id' => 'ER-4', 'financial_year' => '2026']),
        workRow(['code' => 'W-5', 'erupantor_id' => 'ER-5', 'fund_source_code' => 'NEW', 'fund_source_name' => '']),
    ]]), $this->state);

    expect($report->counts['Works'])->toMatchArray(['created' => 1, 'failed' => 4])
        ->and(collect($report->issues)->pluck('row')->all())->toBe([3, 4, 5, 6])
        ->and(Work::withoutGlobalScopes()->pluck('code')->all())->toBe(['W-1']);
});

it('skips duplicate releases and refuses releases above the sanction', function () {
    District::create(['lgd_code' => 'L1', 'name_en' => 'One']);
    $rel = ['work_code', 'amount_rs', 'released_on', 'order_no'];
    $path = workbook([
        'Works' => [WORK_HEADERS, workRow()],
        'Releases' => [$rel, ['ER-1', '1000000', '2026-06-01', 'R1'], ['ER-1', '1000000', '2026-06-01', 'R1'], ['W-1', '600000', '2026-07-01', 'R2']],
    ]);

    $report = importFile($path, $this->state);

    expect($report->counts['Releases'])->toMatchArray(['created' => 1, 'skipped' => 1, 'failed' => 1])
        ->and((int) FundRelease::withoutGlobalScopes()->sum('amount'))->toBe(10_00_000_00);
});

it('keeps a DPC to their own district and away from masters', function () {
    $mine = District::create(['lgd_code' => 'L1', 'name_en' => 'Mine']);
    District::create(['lgd_code' => 'L2', 'name_en' => 'Other']);
    $dpc = userWithRole(Role::DPC, ['level' => 'district', 'district_id' => $mine->id]);

    $report = importFile(workbook([
        'Districts' => [['lgd_code', 'name_en'], ['L9', 'Sneaky']],
        'Works' => [WORK_HEADERS, workRow(), workRow(['code' => 'W-2', 'erupantor_id' => 'ER-2', 'district_lgd_code' => 'L2'])],
    ]), $dpc);

    expect(District::where('lgd_code', 'L9')->exists())->toBeFalse()
        ->and($report->counts['Works'])->toMatchArray(['created' => 1, 'failed' => 1])
        ->and(collect($report->issues)->pluck('message')->implode(' '))->toContain('outside your district');
});

it('runs preview then import through the page', function () {
    Storage::fake('local');
    District::create(['lgd_code' => 'L1', 'name_en' => 'One']);
    $file = UploadedFile::fake()->createWithContent('works.xlsx', file_get_contents(workbook(['Works' => [WORK_HEADERS, workRow()]])));
    $this->actingAs($this->state);

    Livewire::test(Import::class)
        ->set('file', $file)
        ->call('runPreview')
        ->assertHasNoErrors()
        ->assertSee('Will create')
        ->call('import')
        ->assertSee('Import finished.');

    expect(Work::withoutGlobalScopes()->count())->toBe(1);
});

it('serves the template to admins only', function () {
    $this->actingAs($this->state)->get(route('import.template'))->assertOk()->assertDownload('dcp-pragati-import-template.xlsx');
    $this->actingAs(userWithRole(Role::STATE_VIEWER))->get(route('import.template'))->assertForbidden();
});

const USER_HEADERS = ['mobile', 'name', 'role', 'designation', 'district_lgd_code', 'co_district_lgd_code', 'agency_code', 'email', 'preferred_locale'];

it('imports users with roles and jurisdictions, matched on mobile, and assigns field users to works', function () {
    $d = District::create(['lgd_code' => 'L1', 'name_en' => 'One']);
    $c = CoDistrict::create(['lgd_code' => 'L1C', 'district_id' => $d->id, 'name_en' => 'One C']);
    ImplementingAgency::create(['code' => 'PWD', 'name_en' => 'PWD div']);

    $report = importFile(workbook([
        'Users' => [
            USER_HEADERS,
            ['+91 98765 00001', 'DPC One', 'dpc', 'DPO', 'L1', '', '', 'dpc1@example.org', 'as'],
            ['9876500002', 'Co-district officer', 'co_district_officer', '', '', 'L1C', '', '', ''],
            ['9876500003', 'Agency engineer', 'agency_user', 'EE', 'L1', '', 'PWD', '', ''],
            ['9876500004', 'JE One', 'field_user', 'JE', '', '', '', '', 'bn'],
            ['12345', 'Bad mobile', 'field_user', '', '', '', '', '', ''],
            ['9876500005', 'No district', 'dpc', '', '', '', '', '', ''],
            ['9876500006', 'Root', 'system_admin', '', '', '', '', '', ''],
        ],
        'Works' => [array_merge(WORK_HEADERS, ['field_user_mobile']), array_merge(workRow(), ['9876500004'])],
    ]), $this->state);

    expect($report->counts['Users'])->toMatchArray(['created' => 4, 'failed' => 3])
        ->and(collect($report->issues)->where('level', 'warning')->count())->toBe(3); // web users without a password

    $dpc = User::where('mobile', '9876500001')->sole();
    expect($dpc->hasRole(Role::DPC))->toBeTrue()
        ->and($dpc->password)->toBeNull()
        ->and($dpc->preferred_locale)->toBe('as')
        ->and($dpc->jurisdictions()->sole()->only(['level', 'district_id']))->toBe(['level' => 'district', 'district_id' => $d->id]);
    expect(User::where('mobile', '9876500002')->sole()->jurisdictions()->sole()->co_district_id)->toBe($c->id);

    $field = User::where('mobile', '9876500004')->sole();
    expect(Work::withoutGlobalScopes()->sole()->assigned_field_user_id)->toBe($field->id);

    // Re-import changes the role and replaces the jurisdiction: no duplicate user, and it is audited.
    $again = importFile(workbook(['Users' => [USER_HEADERS, ['9876500001', 'DPC One', 'state_viewer', 'DPO', '', '', '', '', '']]]), $this->state);
    $dpc = $dpc->fresh();
    expect($again->counts['Users']['updated'])->toBe(1)
        ->and(User::where('mobile', '9876500001')->count())->toBe(1)
        ->and($dpc->hasRole(Role::STATE_VIEWER))->toBeTrue()
        ->and($dpc->hasRole(Role::DPC))->toBeFalse()
        ->and($dpc->jurisdictions()->count())->toBe(0)
        ->and(Activity::where('event', 'access_changed')->where('subject_id', $dpc->id)->count())->toBe(2);
});

it('keeps a DPC to field, agency and co-district users in their own district', function () {
    $mine = District::create(['lgd_code' => 'L1', 'name_en' => 'Mine']);
    $other = District::create(['lgd_code' => 'L2', 'name_en' => 'Other']);
    CoDistrict::create(['lgd_code' => 'L2C', 'district_id' => $other->id, 'name_en' => 'Other C']);
    $dpc = userWithRole(Role::DPC, ['level' => 'district', 'district_id' => $mine->id]);
    $otherDpc = userWithRole(Role::DPC, ['level' => 'district', 'district_id' => $other->id]);
    $otherField = userWithRole(Role::FIELD_USER);
    Work::factory()->inDistrict($other)->create(['assigned_field_user_id' => $otherField->id]);

    $report = importFile(workbook(['Users' => [
        USER_HEADERS,
        ['9876500010', 'JE Mine', 'field_user', '', '', '', '', '', ''],
        ['9876500011', 'Another DPC', 'dpc', '', 'L1', '', '', '', ''],
        ['9876500012', 'Co-district elsewhere', 'co_district_officer', '', '', 'L2C', '', '', ''],
        [$otherDpc->mobile, 'Hijack', 'field_user', '', '', '', '', '', ''],
        [$otherField->mobile, 'Renamed', 'field_user', '', '', '', '', '', ''],
        [$dpc->mobile, 'Me', 'field_user', '', '', '', '', '', ''],
    ]]), $dpc);

    expect($report->counts['Users'])->toMatchArray(['created' => 1, 'failed' => 5])
        ->and($otherDpc->fresh()->hasRole(Role::DPC))->toBeTrue()
        ->and($otherField->fresh()->name)->not->toBe('Renamed')
        ->and($dpc->fresh()->hasRole(Role::DPC))->toBeTrue();
});

it('skips the Users sheet for importers without manage users', function () {
    $d = District::create(['lgd_code' => 'L1', 'name_en' => 'One']);
    $u = userWithRole(Role::DPC, ['level' => 'district', 'district_id' => $d->id]);
    SpatieRole::findByName(Role::DPC)->revokePermissionTo(Permission::MANAGE_USERS);

    $report = importFile(workbook(['Users' => [USER_HEADERS, ['9876500020', 'JE', 'field_user', '', '', '', '', '', '']]]), $u->fresh());

    expect(User::where('mobile', '9876500020')->exists())->toBeFalse()
        ->and($report->issues[0]['message'])->toContain('permission to manage users');
});
