<?php

use App\Domain\Access\Role;
use App\Domain\Auth\OtpSender;
use App\Domain\Grievances\GrievanceService;
use App\Jobs\SendCitizenSms;
use App\Livewire\Grievances\Index as GrievancesIndex;
use App\Livewire\Grievances\Show as GrievancesShow;
use App\Livewire\PublicSite\GrievanceForm;
use App\Livewire\PublicSite\GrievanceTrack;
use App\Models\CoDistrict;
use App\Models\District;
use App\Models\Grievance;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

class GrievanceCapturingOtpSender implements OtpSender
{
    public array $sent = [];

    public function send(string $mobile, string $code, string $locale = 'en'): void
    {
        $this->sent[$mobile] = $code;
    }
}

beforeEach(function () {
    Storage::fake('local');
    Queue::fake();
    config(['dcp.public.grievances' => true, 'dcp.public.portal' => true]);
    $this->otp = new GrievanceCapturingOtpSender;
    app()->instance(OtpSender::class, $this->otp);

    $this->dA = District::create(['lgd_code' => 'GA', 'name_en' => 'Grievance A']);
    $this->dB = District::create(['lgd_code' => 'GB', 'name_en' => 'Grievance B']);
    $this->coA = CoDistrict::create(['lgd_code' => 'GA1', 'district_id' => $this->dA->id, 'name_en' => 'Co A1']);
    $this->dpcA = userWithRole(Role::DPC, ['level' => 'district', 'district_id' => $this->dA->id]);
    $this->dpcB = userWithRole(Role::DPC, ['level' => 'district', 'district_id' => $this->dB->id]);
});

function fileGrievance(array $overrides = []): Grievance
{
    return app(GrievanceService::class)->submit($overrides + [
        'district_id' => test()->dA->id, 'co_district_id' => null, 'category' => 'quality',
        'subject' => 'Road broken', 'description' => 'The road surface broke within two months of laying.',
        'location_text' => null, 'lat' => null, 'lng' => null, 'work_reference' => null,
        'citizen_name' => 'A citizen', 'citizen_mobile' => '9876543210', 'citizen_locale' => 'en',
    ]);
}

it('files a grievance after the mobile is verified by OTP, and gives a reference', function () {
    $form = Livewire::test(GrievanceForm::class)
        ->set('district', $this->dA->id)
        ->set('coDistrict', $this->coA->id)
        ->set('category', 'safety')
        ->set('subject', 'Open pit near the school')
        ->set('description', 'A deep pit is open next to the road without any barricade.')
        ->set('mobile', '+91 98765 43210')
        ->set('consent', true)
        ->set('photo', UploadedFile::fake()->image('pit.jpg', 800, 600))
        ->call('sendCode')
        ->assertHasNoErrors()
        ->assertSet('step', 'verify');

    expect(Grievance::withoutGlobalScopes()->count())->toBe(0, 'nothing is stored before the OTP');

    $form->set('code', '000000')->call('verify')->assertHasErrors('code');
    $form->set('code', $this->otp->sent['9876543210'])->call('verify')->assertSet('step', 'done');

    $g = Grievance::withoutGlobalScopes()->sole();
    expect($g->ref_no)->toMatch('/^GRV-\d{2}-\d{6}$/')
        ->and($g->citizen_mobile)->toBe('9876543210')
        ->and($g->photo_path)->not->toBeNull()
        ->and($g->status)->toBe('submitted')
        ->and($g->events()->first()->is_public)->toBeTrue();
    $form->assertSet('ref', $g->ref_no);
    Storage::disk('local')->assertExists($g->photo_path);
    Queue::assertPushed(SendCitizenSms::class, fn ($job) => $job->mobile === '9876543210' && str_contains($job->text, $g->ref_no));

    // Personal data stays out of the audit log.
    $logged = Activity::where('subject_type', Grievance::class)->get()->pluck('properties')->toJson();
    expect($logged)->not->toContain('9876543210')->not->toContain('A citizen');
});

it('needs consent and a valid mobile, and rejects a co-district from another district', function () {
    Livewire::test(GrievanceForm::class)
        ->set('district', $this->dB->id)->set('category', 'quality')->set('subject', 'Something')
        ->set('description', 'Long enough description of the problem.')
        ->set('mobile', '12345')
        ->call('sendCode')
        ->assertHasErrors(['mobile', 'consent']);

    expect(fn () => fileGrievance(['district_id' => $this->dB->id, 'co_district_id' => $this->coA->id]))
        ->toThrow(InvalidArgumentException::class);
});

it('lets the citizen track with reference and mobile, showing only public updates', function () {
    $g = fileGrievance();
    $service = app(GrievanceService::class);
    $service->note($g, $this->dpcA, 'Internal: contractor is a repeat offender', false);
    $service->transition($g, $this->dpcA, Grievance::IN_PROGRESS);

    Livewire::test(GrievanceTrack::class)
        ->set('ref', strtolower($g->ref_no))->set('mobile', '9000000000')->call('find')
        ->assertHasErrors('ref');

    Livewire::test(GrievanceTrack::class)
        ->set('ref', $g->ref_no)->set('mobile', '98765 43210')->call('find')
        ->assertHasNoErrors()
        ->assertSee('Being looked into')
        ->assertDontSee('repeat offender')
        ->assertDontSee($this->dpcA->name)
        ->assertDontSee('A citizen');
});

it('shows officers only grievances in their own district', function () {
    $mine = fileGrievance();
    $other = fileGrievance(['district_id' => $this->dB->id, 'subject' => 'Bridge in B']);

    $this->actingAs($this->dpcA);
    Livewire::test(GrievancesIndex::class)->assertSee('Road broken')->assertDontSee('Bridge in B');
    $this->get(route('grievances.show', $other))->assertNotFound();
    $this->get(route('grievances.show', $mine))->assertOk();

    $this->actingAs(userWithRole(Role::FIELD_USER));
    $this->get(route('grievances.index'))->assertForbidden();
});

it('moves a grievance through its states, needs a note to close, and texts the citizen', function () {
    $g = fileGrievance();
    $this->actingAs($this->dpcA);

    $page = Livewire::test(GrievancesShow::class, ['grievance' => $g])
        ->call('move', 'acknowledged')->assertHasNoErrors()
        ->set('closeAs', 'resolved')->set('closeNote', 'short')->call('close')->assertHasErrors('closeNote')
        ->set('closeNote', 'Site inspected; the contractor relaid the damaged stretch.')->call('close')->assertHasNoErrors();

    $g->refresh();
    expect($g->status)->toBe('resolved')
        ->and($g->acknowledged_at)->not->toBeNull()
        ->and($g->closed_at)->not->toBeNull()
        ->and($g->assigned_to)->toBe($this->dpcA->id)
        ->and($g->resolution_note)->toContain('relaid');
    Queue::assertPushed(SendCitizenSms::class, fn ($job) => str_contains($job->text, 'Resolved'));

    // Final: no more moves.
    $page->call('move', 'in_progress')->assertHasErrors('action');
});

it('logs who looked at the citizen contact', function () {
    $g = fileGrievance();
    $this->actingAs($this->dpcA);

    Livewire::test(GrievancesShow::class, ['grievance' => $g])
        ->assertDontSee('9876543210')
        ->call('revealContact')
        ->assertSee('9876543210');

    expect(Activity::where('description', 'viewed citizen contact')->where('causer_id', $this->dpcA->id)->exists())->toBeTrue();
});

it('links a work only from the same district', function () {
    $g = fileGrievance();
    [$workA] = workWithFieldUser(['district_id' => $this->dA->id]);
    [$workB] = workWithFieldUser(['district_id' => $this->dB->id]);
    $this->actingAs($this->dpcA);

    Livewire::test(GrievancesShow::class, ['grievance' => $g])
        ->set('workCode', $workB->code)->call('linkWork')->assertHasErrors('workCode')
        ->set('workCode', $workA->code)->call('linkWork')->assertHasNoErrors();

    expect($g->fresh()->work_id)->toBe($workA->id);
});

it('clears citizen contact details after the retention period', function () {
    $g = fileGrievance();
    app(GrievanceService::class)->transition($g, $this->dpcA, Grievance::REJECTED, 'Not a DCP work; forwarded to PWD.');
    $g->forceFill(['closed_at' => now()->subDays(config('dcp.grievances.retention_days') + 1)])->save();

    expect(app(GrievanceService::class)->purgePersonalData())->toBe(1);
    $g->refresh();
    expect($g->citizen_mobile)->toBeNull()->and($g->citizen_name)->toBeNull()->and($g->personal_data_purged_at)->not->toBeNull();
});

it('hides every grievance page when the feature is off', function () {
    config(['dcp.public.grievances' => false]);
    $this->get(route('grievances.create'))->assertNotFound();
    $this->get(route('grievances.track'))->assertNotFound();
    $this->actingAs($this->dpcA)->get(route('grievances.index'))->assertNotFound();
});
