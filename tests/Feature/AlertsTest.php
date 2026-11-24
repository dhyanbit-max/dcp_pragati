<?php

use App\Domain\Access\Role;
use App\Domain\Alerts\AlertEvaluator;
use App\Domain\Alerts\AlertNotifier;
use App\Domain\Alerts\DigestSender;
use App\Domain\Alerts\Escalator;
use App\Domain\Progress\ApprovalService;
use App\Jobs\SendAlertNotification;
use App\Livewire\Alerts\Inbox;
use App\Mail\AlertDigest;
use App\Mail\AlertMail;
use App\Models\Alert;
use App\Models\AlertNotification;
use App\Models\District;
use App\Models\ProgressUpdate;
use App\Models\Work;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    // Midday IST so quiet hours never interfere unless a test asks for them.
    $this->travelTo(Carbon::parse('2026-12-01 12:00:00', 'Asia/Kolkata'));
    $this->district = District::create(['lgd_code' => 'AL', 'name_en' => 'Alert district']);
    [$this->work, $this->field] = workWithFieldUser(['district_id' => $this->district->id, 'sanction_date' => '2026-11-01']);
    $this->dpc = userWithRole(Role::DPC, ['level' => 'district', 'district_id' => $this->district->id]);
    $this->dc = userWithRole(Role::DISTRICT_COLLECTOR, ['level' => 'district', 'district_id' => $this->district->id]);
    $this->state = userWithRole(Role::STATE_ADMIN);
    // Recording the release above already triggered an alert check; start each test clean.
    AlertNotification::query()->delete();
    Alert::withoutGlobalScopes()->delete();
});

it('re-checks alerts as soon as a release is recorded', function () {
    $this->work->releases()->create(['amount' => 10_000_00, 'released_on' => now()->toDateString()]);

    expect(openAlert($this->work, 'A01'))->not->toBeNull();
});

function evaluateAlerts(?array $ids = null): array
{
    return app(AlertEvaluator::class)->evaluate($ids);
}

function openAlert(Work $work, string $code): ?Alert
{
    return Alert::withoutGlobalScopes()->where('work_id', $work->id)->where('alert_code', $code)->whereIn('status', ['open', 'acknowledged'])->first();
}

it('opens A01 once a work has had no update for 10 days, and texts the field officer', function () {
    // Sanctioned 30 days ago, never updated.
    evaluateAlerts();

    $alert = openAlert($this->work, 'A01');
    expect($alert)->not->toBeNull()
        ->and($alert->details_json['days'])->toBe(30);

    $sms = AlertNotification::where('alert_id', $alert->id)->where('channel', 'sms')->sole();
    expect($sms->recipient_user_id)->toBe($this->field->id)
        ->and($sms->status)->toBe('sent')
        ->and($sms->body)->toContain($this->work->code)->toContain('30 days');
});

it('does not open duplicates on repeated runs', function () {
    evaluateAlerts();
    evaluateAlerts();

    expect(Alert::withoutGlobalScopes()->where('alert_code', 'A01')->count())->toBe(1)
        ->and(AlertNotification::where('channel', 'sms')->count())->toBe(1);
});

it('auto-closes A01 when an update is approved', function () {
    evaluateAlerts();
    $id = $this->actingAs($this->field, 'sanctum')->postJson('/api/v1/progress-updates', [
        'client_uuid' => (string) Str::uuid(), 'work_id' => $this->work->id, 'stage_item_id' => stageId($this->work, 2),
        'physical_pct' => 20, 'spent_cumulative' => 20_000_00, 'captured_at' => now()->toIso8601String(),
        'photo_uuids' => [uploadPhoto($this, $this->field, $this->work), uploadPhoto($this, $this->field, $this->work)],
    ])->assertCreated()->json('id');

    app(ApprovalService::class)->approve(ProgressUpdate::withoutGlobalScopes()->find($id), $this->dpc);

    expect(openAlert($this->work, 'A01'))->toBeNull()
        ->and(Alert::withoutGlobalScopes()->where('alert_code', 'A01')->value('status'))->toBe('auto_closed');
});

it('opens A02 for a missed milestone and A04 for spend ahead of progress', function () {
    $this->work->stages()->create(['stage_template_item_id' => stageId($this->work, 2), 'planned_date' => now()->subDays(10)->toDateString()]);
    $this->work->forceFill(['spent_total' => 40_000_00, 'physical_pct' => 5, 'status' => 'in_progress', 'last_accepted_update_at' => now()])->save();

    evaluateAlerts();

    expect(openAlert($this->work, 'A02')->details_json['stage'])->toBe('Foundation')
        ->and(openAlert($this->work, 'A04')->details_json)->toMatchArray(['financial_pct' => 40.0, 'physical_pct' => 5.0])
        ->and(openAlert($this->work, 'A01'))->toBeNull(); // updated today
});

it('opens A03 when an old release is mostly unspent', function () {
    // Release (₹50,000) dated a month ago; push it past 60 days.
    $this->work->releases()->update(['released_on' => now()->subDays(70)->toDateString()]);
    $this->work->forceFill(['spent_total' => 10_000_00])->save(); // 20% utilisation

    evaluateAlerts();

    expect(openAlert($this->work, 'A03')->details_json['utilisation_pct'])->toEqual(20);
});

it('opens A05 for approvals waiting more than 3 days and tells the DPC', function () {
    $this->dpc->update(['email' => 'dpc@example.test']);
    $this->actingAs($this->field, 'sanctum')->postJson('/api/v1/progress-updates', [
        'client_uuid' => (string) Str::uuid(), 'work_id' => $this->work->id, 'stage_item_id' => stageId($this->work, 2),
        'physical_pct' => 20, 'spent_cumulative' => 20_000_00, 'captured_at' => now()->toIso8601String(),
        'photo_uuids' => [uploadPhoto($this, $this->field, $this->work), uploadPhoto($this, $this->field, $this->work)],
    ])->assertCreated();
    $this->travel(4)->days();

    Mail::fake();
    evaluateAlerts();

    $alert = openAlert($this->work, 'A05');
    expect($alert->details_json['pending'])->toBe(1)
        ->and(AlertNotification::where('alert_id', $alert->id)->where('channel', 'email')->value('recipient_user_id'))->toBe($this->dpc->id);
    Mail::assertSent(AlertMail::class, fn ($m) => $m->hasTo('dpc@example.test'));
});

it('escalates to the DPC after 3 days, the DC after 10 and the state after 24', function () {
    evaluateAlerts();
    $alert = openAlert($this->work, 'A01');

    $this->travel(2)->days();
    app(Escalator::class)->run();
    expect($alert->fresh()->level)->toBe(0);

    $this->travel(2)->days();
    app(Escalator::class)->run();
    expect($alert->fresh()->level)->toBe(1)
        ->and(AlertNotification::where('alert_id', $alert->id)->where('level', 1)->pluck('recipient_user_id')->unique()->all())->toBe([$this->dpc->id]);

    $this->travel(7)->days();
    app(Escalator::class)->run();
    expect($alert->fresh()->level)->toBe(2)
        ->and(AlertNotification::where('alert_id', $alert->id)->where('level', 2)->value('recipient_user_id'))->toBe($this->dc->id);

    $this->travel(14)->days();
    app(Escalator::class)->run();
    expect($alert->fresh()->level)->toBe(3)
        ->and(AlertNotification::where('alert_id', $alert->id)->where('level', 3)->value('recipient_user_id'))->toBe($this->state->id);
});

it('pauses escalation while acknowledged, then reopens', function () {
    evaluateAlerts();
    $alert = openAlert($this->work, 'A01');
    $this->actingAs($this->field, 'sanctum')->postJson("/api/v1/alerts/{$alert->id}/acknowledge", ['note' => 'Site flooded, will visit Friday'])->assertOk();

    $this->travel(5)->days();
    app(Escalator::class)->run();
    expect($alert->fresh())->status->toBe('acknowledged')->level->toBe(0);

    $this->travel(3)->days(); // pause (7 days) is over
    app(Escalator::class)->run();
    expect($alert->fresh())->status->toBe('open')->level->toBe(1);
});

it('does not reopen a manually resolved alert during the snooze period', function () {
    evaluateAlerts();
    $alert = openAlert($this->work, 'A01');
    $this->actingAs($this->dpc, 'sanctum')->postJson("/api/v1/alerts/{$alert->id}/resolve", ['note' => 'Work suspended by order'])->assertOk();

    evaluateAlerts();
    expect(openAlert($this->work, 'A01'))->toBeNull();

    $this->travel(8)->days();
    evaluateAlerts();
    expect(openAlert($this->work, 'A01'))->not->toBeNull();
});

it('holds SMS during quiet hours until 08:00 IST', function () {
    Queue::fake();
    $this->travelTo(Carbon::parse('2026-12-01 22:30:00', 'Asia/Kolkata'));

    evaluateAlerts();

    $sms = AlertNotification::where('channel', 'sms')->sole();
    expect($sms->status)->toBe('queued')
        ->and($sms->scheduled_for->setTimezone('Asia/Kolkata')->format('Y-m-d H:i'))->toBe('2026-12-02 08:00');
    Queue::assertPushed(SendAlertNotification::class, fn ($job) => $job->delay !== null);
});

it('computes the end of quiet hours correctly', function () {
    $n = app(AlertNotifier::class);
    $at = fn ($t) => $n->afterQuietHours(Carbon::parse($t, 'Asia/Kolkata'))?->setTimezone('Asia/Kolkata')->format('Y-m-d H:i');

    expect($at('2026-12-01 07:59'))->toBe('2026-12-01 08:00')
        ->and($at('2026-12-01 08:00'))->toBeNull()
        ->and($at('2026-12-01 20:59'))->toBeNull()
        ->and($at('2026-12-01 21:00'))->toBe('2026-12-02 08:00');
});

it('sends WhatsApp only to users who opted in', function () {
    evaluateAlerts();
    expect(AlertNotification::where('channel', 'whatsapp')->count())->toBe(0);

    Alert::query()->withoutGlobalScopes()->delete();
    $this->field->update(['whatsapp_opt_in' => true]);
    evaluateAlerts();
    expect(AlertNotification::where('channel', 'whatsapp')->value('recipient_user_id'))->toBe($this->field->id);
});

it('falls back to the DPC when a work has no field officer', function () {
    $this->work->forceFill(['assigned_field_user_id' => null])->save();
    $this->work->assignments()->delete();

    evaluateAlerts();

    expect(AlertNotification::where('channel', 'sms')->value('recipient_user_id'))->toBe($this->dpc->id);
});

it('shows only alerts in the viewer\'s jurisdiction and lets them acknowledge', function () {
    $other = District::create(['lgd_code' => 'OT', 'name_en' => 'Other']);
    [$otherWork] = workWithFieldUser(['district_id' => $other->id, 'sanction_date' => '2026-11-01']);
    evaluateAlerts();
    $this->actingAs($this->dpc);

    Livewire::test(Inbox::class)
        ->assertSee($this->work->code)
        ->assertDontSee($otherWork->code)
        ->call('start', openAlert($this->work, 'A01')->id, 'acknowledge')
        ->call('confirm')
        ->assertHasErrors('note')
        ->set('note', 'JE on leave, assigned another')
        ->call('confirm')
        ->assertHasNoErrors();

    expect(openAlert($this->work, 'A01')->status)->toBe('acknowledged');
});

it('lists alerts over the API for the field app', function () {
    evaluateAlerts();

    $this->actingAs($this->field, 'sanctum')->getJson('/api/v1/alerts')
        ->assertOk()
        ->assertJsonPath('data.0.code', 'A01')
        ->assertJsonPath('data.0.work_code', $this->work->code);
});

it('emails the daily digest to DPCs and DCs with something to report', function () {
    Mail::fake();
    $this->dpc->update(['email' => 'dpc@example.test']);
    $this->dc->update(['email' => 'dc@example.test']);
    evaluateAlerts();

    $sent = app(DigestSender::class)->send();

    expect($sent)->toBe(2);
    Mail::assertSent(AlertDigest::class, fn ($m) => $m->hasTo('dpc@example.test') && $m->newAlerts->count() === 1);
});

it('runs the scheduled alert commands', function () {
    $this->artisan('alerts:evaluate')->expectsOutputToContain('Alerts opened: 1')->assertSuccessful();
    $this->artisan('alerts:escalate')->assertSuccessful();
    $this->artisan('alerts:digest')->assertSuccessful();
});
