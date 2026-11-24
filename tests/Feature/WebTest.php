<?php

use App\Domain\Access\Role;
use App\Domain\Auth\OtpSender;
use App\Domain\Progress\ApprovalService;
use App\Livewire\Approvals\Queue;
use App\Livewire\Dashboard\Overview;
use App\Livewire\Works\Index as WorksIndex;
use App\Models\District;
use App\Models\ProgressUpdate;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

class WebCapturingOtpSender implements OtpSender
{
    public array $sent = [];

    public function send(string $mobile, string $code, string $locale = 'en'): void
    {
        $this->sent[$mobile] = $code;
    }
}

beforeEach(function () {
    Storage::fake('local');
    $this->districtA = District::create(['lgd_code' => 'WA', 'name_en' => 'Alpha district']);
    $this->districtB = District::create(['lgd_code' => 'WB', 'name_en' => 'Beta district']);
    [$this->workA, $this->fieldA] = workWithFieldUser(['district_id' => $this->districtA->id]);
    [$this->workB, $this->fieldB] = workWithFieldUser(['district_id' => $this->districtB->id]);
    $this->dpcA = userWithRole(Role::DPC, ['level' => 'district', 'district_id' => $this->districtA->id]);
});

function submitUpdate($test, User $field, $work, array $photoMeta = []): ProgressUpdate
{
    $photos = [uploadPhoto($test, $field, $work, $photoMeta), uploadPhoto($test, $field, $work)];
    $id = $test->actingAs($field, 'sanctum')->postJson('/api/v1/progress-updates', [
        'client_uuid' => (string) Str::uuid(), 'work_id' => $work->id, 'stage_item_id' => stageId($work, 2),
        'physical_pct' => 20, 'spent_cumulative' => 20_000_00, 'captured_at' => now()->toIso8601String(), 'photo_uuids' => $photos,
    ])->assertCreated()->json('id');
    app('auth')->forgetGuards();

    return ProgressUpdate::withoutGlobalScopes()->findOrFail($id);
}

describe('login', function () {
    beforeEach(function () {
        $this->otp = new WebCapturingOtpSender;
        app()->instance(OtpSender::class, $this->otp);
    });

    it('signs in with password then OTP', function () {
        $this->post('/login', ['mobile' => $this->dpcA->mobile, 'password' => 'password'])->assertRedirect(route('login.otp'));
        $this->post('/login/otp', ['otp' => $this->otp->sent[$this->dpcA->mobile]])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($this->dpcA);
    });

    it('rejects a wrong password without sending an OTP', function () {
        $this->post('/login', ['mobile' => $this->dpcA->mobile, 'password' => 'nope'])->assertSessionHasErrors('mobile');
        expect($this->otp->sent)->toBe([]);
    });

    it('keeps field users out of the web app', function () {
        $this->post('/login', ['mobile' => $this->fieldA->mobile, 'password' => 'password'])->assertSessionHasErrors('mobile');
    });

    it('redirects guests to login', function () {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    });
});

it('shows a DPC only their district on the dashboard', function () {
    $this->actingAs($this->dpcA);

    Livewire::test(Overview::class)
        ->assertSee('Alpha district')
        ->assertDontSee('Beta district')
        ->assertViewHas('kpis', fn ($k) => $k['works'] === 1);
});

it('drills down from districts to works for state users', function () {
    $this->actingAs(userWithRole(Role::STATE_VIEWER));

    Livewire::test(Overview::class)
        ->assertSee('Alpha district')->assertSee('Beta district')
        ->call('drill', $this->districtB->id)
        ->call('drill', $this->districtB->id, 'none')
        ->assertViewHas('level', 'works')
        ->assertSee($this->workB->code)
        ->assertDontSee($this->workA->code);
});

it('approves a clean update from the queue and updates the work', function () {
    $update = submitUpdate($this, $this->fieldA, $this->workA);
    $this->actingAs($this->dpcA);

    Livewire::test(Queue::class)
        ->assertSet('tab', 'clean')
        ->call('review', $update->id)
        ->call('approve')
        ->assertHasNoErrors()
        ->assertSet('flash', 'Approved.');

    expect($this->workA->fresh()->physical_pct)->toBe(20.0);
});

it('requires a note before approving a flagged update', function () {
    $update = submitUpdate($this, $this->fieldA, $this->workA, ['lat' => 26.3, 'lng' => 91.9]);
    $this->actingAs($this->dpcA);

    Livewire::test(Queue::class)
        ->assertSet('tab', 'flagged')
        ->call('review', $update->id)
        ->call('approve')
        ->assertHasErrors('note')
        ->set('note', 'Verified by phone with JE')
        ->call('approve')
        ->assertHasNoErrors();

    expect($update->fresh()->status)->toBe(ProgressUpdate::STATUS_ACCEPTED);
});

it('bulk-approves selected clean updates', function () {
    $update = submitUpdate($this, $this->fieldA, $this->workA);
    $this->actingAs($this->dpcA);

    Livewire::test(Queue::class)->set('selected', [$update->id])->call('bulkApprove')->assertSet('selected', []);

    expect($update->fresh()->status)->toBe(ProgressUpdate::STATUS_ACCEPTED);
});

it('cannot act on another district\'s update from the queue', function () {
    $update = submitUpdate($this, $this->fieldB, $this->workB);
    $this->actingAs($this->dpcA);

    // The jurisdiction scope hides the record, so the lookup fails (a 404 over HTTP).
    expect(fn () => Livewire::test(Queue::class)->set('reviewing', $update->id)->call('approve'))
        ->toThrow(ModelNotFoundException::class);
    expect($update->fresh()->status)->toBe(ProgressUpdate::STATUS_PENDING);
});

it('renders the work record with the progress chart after approval', function () {
    $update = submitUpdate($this, $this->fieldA, $this->workA);
    app(ApprovalService::class)->approve($update, $this->dpcA);
    $this->actingAs($this->dpcA);

    $this->get(route('works.show', $this->workA))
        ->assertOk()
        ->assertSee('Physical progress')
        ->assertSee('20%');
});

it('hides other districts\' works and photos', function () {
    $update = submitUpdate($this, $this->fieldB, $this->workB);
    $photo = $update->photos()->withoutGlobalScopes()->first();
    $this->actingAs($this->dpcA);

    $this->get(route('works.show', $this->workB))->assertNotFound();
    $this->get(route('photos.show', $photo))->assertNotFound();
});

it('lets the DPC in the same district view a photo', function () {
    $update = submitUpdate($this, $this->fieldA, $this->workA);
    $photo = $update->photos()->withoutGlobalScopes()->first();

    $this->actingAs($this->dpcA)->get(route('photos.show', $photo))->assertOk();
});

it('shows the last update in calendar days on the works list', function () {
    $this->actingAs(userWithRole(Role::STATE_VIEWER));
    $this->travelTo(now()->setTime(9, 0));
    $this->workA->forceFill(['last_accepted_update_at' => now()->subHours(2)])->save();
    $this->workB->forceFill(['last_accepted_update_at' => now()->subDay()->setTime(23, 0)])->save();

    Livewire::test(WorksIndex::class)
        ->assertSee('Today')->assertSee('7:00 AM')
        ->assertSee('Yesterday')->assertSee('11:00 PM')
        ->assertDontSee('0 days ago');

    $this->workB->forceFill(['last_accepted_update_at' => now()->subDays(12)])->save();
    Livewire::test(WorksIndex::class)->assertSee('12 days ago')->assertSee(now()->subDays(12)->format(now()->subDays(12)->isCurrentYear() ? 'j M, g:i A' : 'j M Y, g:i A'))->assertDontSee('Yesterday');
});

it('keeps the latest capture time when an older update is approved later', function () {
    $newer = submitUpdate($this, $this->fieldA, $this->workA);
    $older = submitUpdate($this, $this->fieldA, $this->workA);
    $older->forceFill(['captured_at' => now()->subHours(3)])->save();

    app(ApprovalService::class)->approve($newer, $this->dpcA);
    app(ApprovalService::class)->approve($older->fresh(), $this->dpcA);

    expect($this->workA->fresh()->last_accepted_update_at->equalTo($newer->captured_at))->toBeTrue();
});

it('shows who submitted the last accepted update on the works list', function () {
    $this->fieldA->forceFill(['name' => 'Junior Engineer Alpha'])->save();
    $update = submitUpdate($this, $this->fieldA, $this->workA);
    app(ApprovalService::class)->approve($update, $this->dpcA);
    submitUpdate($this, $this->fieldB, $this->workB); // pending: not shown

    $this->actingAs(userWithRole(Role::STATE_VIEWER));
    Livewire::test(WorksIndex::class)
        ->assertSee('by Junior Engineer Alpha')
        ->assertDontSee('by '.$this->fieldB->name);
});

it('sends the dashboard map the same figures as the table, within the viewer\'s jurisdiction', function () {
    $this->workA->forceFill(['status' => 'in_progress', 'target_completion_date' => now()->subDays(30)->toDateString()])->save();

    $this->actingAs($this->dpcA);
    Livewire::test(Overview::class)
        ->assertDispatched('dashboard-map', function ($name, $p) {
            return $p['level'] === 'district'
                && collect($p['areas'])->pluck('id')->all() === [$this->districtA->id]
                && $p['areas'][0]['works'] === 1 && $p['areas'][0]['flagged'] === 1;
        })
        ->call('drill', $this->districtA->id, 'none')
        ->assertDispatched('dashboard-map', fn ($name, $p) => $p['level'] === 'works'
            && $p['district'] === $this->districtA->id
            && collect($p['works'])->pluck('code')->all() === [$this->workA->code]
            && $p['works'][0]['display'] === 'delayed');

    $this->actingAs(userWithRole(Role::STATE_VIEWER));
    Livewire::test(Overview::class)
        ->assertDispatched('dashboard-map', fn ($name, $p) => count($p['areas']) === 2)
        ->assertSee('Assam at a glance');
});
