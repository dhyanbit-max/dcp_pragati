<?php

use App\Domain\Access\Role;
use App\Domain\Import\WorkbookImporter;
use App\Domain\Reports\ReportRunner;
use App\Livewire\Reports\AqDesk;
use App\Livewire\Reports\Runner;
use App\Mail\WeeklyReports;
use App\Models\AqRequest;
use App\Models\Constituency;
use App\Models\District;
use App\Models\ReportRun;
use App\Models\Work;
use Database\Seeders\ReportTemplatesSeeder;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use OpenSpout\Reader\XLSX\Reader;

beforeEach(function () {
    $this->seed(ReportTemplatesSeeder::class);
    $this->dA = District::create(['lgd_code' => 'RA', 'name_en' => 'Alpha', 'name_as' => 'আলফা']);
    $this->dB = District::create(['lgd_code' => 'RB', 'name_en' => 'Beta']);
    $this->ac = Constituency::create(['ac_no' => 950, 'district_id' => $this->dA->id, 'name_en' => 'Test AC']);

    [$this->w1] = workWithFieldUser(['district_id' => $this->dA->id, 'constituency_id' => $this->ac->id, 'sanctioned_amount' => 100_000_00]);
    [$this->w2] = workWithFieldUser(['district_id' => $this->dA->id, 'sanctioned_amount' => 200_000_00]);
    [$this->w3] = workWithFieldUser(['district_id' => $this->dB->id, 'sanctioned_amount' => 300_000_00]);
    $this->w1->forceFill(['spent_total' => 30_000_00, 'status' => 'in_progress', 'physical_pct' => 40, 'last_accepted_update_at' => now()])->save();
    $this->w2->forceFill(['target_completion_date' => now()->subDays(30)->toDateString()])->save(); // delayed

    $this->state = userWithRole(Role::STATE_ADMIN);
    $this->dpcA = userWithRole(Role::DPC, ['level' => 'district', 'district_id' => $this->dA->id]);
});

function runReport(string $code, $user, array $filters = [], array $params = [])
{
    test()->actingAs($user);

    return app(ReportRunner::class)->run($code, $filters, $params, $user);
}

it('builds the district abstract with correct totals', function () {
    $r = runReport('MIS-01', $this->state);

    expect($r->rows)->toHaveCount(2)
        ->and($r->totals['works'])->toBe(3)
        ->and((int) $r->totals['sanctioned'])->toBe(600_000_00)
        ->and((int) $r->totals['released'])->toBe(150_000_00) // ₹50,000 per work from the helper
        ->and((int) $r->totals['spent'])->toBe(30_000_00)
        ->and($r->totals['utilisation'])->toBe(20.0)
        ->and((int) $r->rows->firstWhere('district', 'Alpha')['delayed'])->toBe(1);
});

it('keeps a DPC to their own district in every report', function () {
    foreach (array_keys(ReportRunner::REPORTS) as $code) {
        $r = runReport($code, $this->dpcA);
        $flat = json_encode($r->rows->all());
        expect($flat)->not->toContain('Beta')->not->toContain($this->w3->code);
    }
    expect(runReport('MIS-02', $this->dpcA)->rows)->toHaveCount(2);
});

it('runs every report for the state and records each run', function () {
    foreach (array_keys(ReportRunner::REPORTS) as $code) {
        runReport($code, $this->state, [], ['group_by' => 'agency']);
    }

    expect(ReportRun::count())->toBe(6)
        ->and(ReportRun::whereNull('duration_ms')->count())->toBe(0);
});

it('lists delayed works with days overdue', function () {
    $r = runReport('MIS-04', $this->state);

    expect($r->rows)->toHaveCount(1)
        ->and($r->rows[0]['code'])->toBe($this->w2->code)
        ->and($r->rows[0]['overdue'])->toBe('Completion')
        ->and($r->rows[0]['days_overdue'])->toBe(30);
});

it('measures freshness per district', function () {
    $r = runReport('MIS-05', $this->state);

    $alpha = $r->rows->firstWhere('name', 'Alpha');
    expect($alpha)->toMatchArray(['active' => 2, 'fresh' => 1, 'fresh_pct' => 50.0, 'never' => 1]);
});

it('downloads Excel with a header row and a totals row in rupees', function () {
    $response = $this->actingAs($this->state)->get(route('reports.export', ['code' => 'MIS-01', 'format' => 'xlsx']));
    $response->assertOk()->assertDownload();

    $path = tempnam(sys_get_temp_dir(), 't').'.xlsx';
    file_put_contents($path, $response->streamedContent());
    $reader = new Reader;
    $reader->open($path);
    $rows = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = $row->toArray();
        }
        break;
    }
    $reader->close();

    expect($rows[0][0])->toBe('District')
        ->and($rows[0][3])->toBe('Sanctioned (₹)')
        ->and(end($rows)[3])->toEqual(600000); // rupees, not paise
});

it('renders PDFs through Gotenberg with embedded Bengali-script fonts and Assamese text', function () {
    Http::fake(['*/forms/chromium/convert/html' => Http::response('%PDF-1.7 fake', 200)]);
    config(['dcp.pdf.gotenberg_url' => 'http://pdf.test']);

    $this->actingAs($this->state)->withSession(['locale' => 'as'])
        ->get(route('reports.export', ['code' => 'MIS-01', 'format' => 'pdf']))
        ->assertOk()->assertDownload();

    Http::assertSent(function (HttpRequest $req) {
        $html = collect($req->data())->firstWhere('name', 'files')['contents'] ?? '';

        return str_contains($req->url(), 'pdf.test/forms/chromium/convert/html')
            && str_contains($html, "font-family:'Noto Sans Bengali'")
            && str_contains($html, 'আলফা');
    });
});

it('falls back gracefully when the PDF service is down', function () {
    Http::fake(['*' => Http::response('', 503)]);
    config(['dcp.pdf.gotenberg_url' => 'http://pdf.test']);

    $this->actingAs($this->state)->from(route('reports'))
        ->get(route('reports.export', ['code' => 'MIS-01', 'format' => 'pdf']))
        ->assertRedirect(route('reports'))
        ->assertSessionHas('status');
});

it('shows a report on screen from the page', function () {
    $this->actingAs($this->dpcA);

    Livewire::test(Runner::class)
        ->set('code', 'MIS-02')
        ->call('show')
        ->assertSee($this->w1->code)
        ->assertDontSee($this->w3->code);
});

it('answers an Assembly Question by constituency and saves it', function () {
    $this->actingAs($this->state);

    Livewire::test(AqDesk::class)
        ->set('questionNo', '123')
        ->set('session', 'Budget Session 2027')
        ->set('constituencies', [$this->ac->id])
        ->set('note', 'Figures as of today.')
        ->call('prepare')
        ->assertHasNoErrors()
        ->assertSee('Assembly Question No. 123')
        ->assertSee($this->w1->code)
        ->assertDontSee($this->w2->code);

    $aq = AqRequest::sole();
    expect($aq->params_json['constituency'])->toBe([$this->ac->id])
        ->and($aq->params_json['works'])->toBe(1);

    $this->get(route('aq.export', [$aq, 'xlsx']))->assertOk()->assertDownload();
});

it('does not let another DPC download someone else\'s AQ reply', function () {
    $this->actingAs($this->state);
    Livewire::test(AqDesk::class)->set('questionNo', '9')->call('prepare');
    $aq = AqRequest::sole();

    $this->actingAs($this->dpcA)->get(route('aq.export', [$aq, 'xlsx']))->assertNotFound();
});

it('keeps reports away from field users and the AQ desk away from agency users', function () {
    $field = Work::withoutGlobalScopes()->find($this->w1->id)->assignedFieldUser;
    $this->actingAs($field)->get(route('reports'))->assertForbidden();
    $this->actingAs(userWithRole(Role::AGENCY_USER))->get(route('aq'))->assertForbidden();
});

it('emails weekly Excel reports scoped to each recipient', function () {
    Mail::fake();
    $this->dpcA->update(['email' => 'dpc@example.test']);
    $this->state->update(['email' => 'state@example.test']);

    $this->artisan('reports:weekly')->expectsOutputToContain('sent: 2')->assertSuccessful();

    Mail::assertSent(WeeklyReports::class, fn ($m) => $m->hasTo('dpc@example.test') && count($m->attachments()) === 2);
    expect(ReportRun::where('scheduled', true)->count())->toBe(4);
});

it('imports constituencies from the workbook', function () {
    $sheets = ['Constituencies' => [[2, ['ac_no' => 77, 'district_lgd_code' => 'RA', 'name_en' => 'Imported AC', 'name_as' => null, 'name_bn' => null, 'parliamentary_constituency' => 'PC']]]];

    $report = app(WorkbookImporter::class)->run($sheets, $this->state, true);

    expect($report->counts['Constituencies']['created'])->toBe(1)
        ->and(Constituency::where('ac_no', 77)->value('district_id'))->toBe($this->dA->id);
});
