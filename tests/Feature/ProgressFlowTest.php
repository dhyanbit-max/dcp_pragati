<?php

use App\Domain\Access\Role;
use App\Models\ProgressUpdate;
use App\Models\Work;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

beforeEach(fn () => Storage::fake('local'));

function updatePayload(Work $work, array $photoUuids, array $overrides = []): array
{
    return $overrides + [
        'client_uuid' => (string) Str::uuid(),
        'work_id' => $work->id,
        'stage_item_id' => stageId($work, 2),
        'physical_pct' => 20,
        'spent_cumulative' => 20_000_00,
        'captured_at' => now()->toIso8601String(),
        'photo_uuids' => $photoUuids,
        'device' => ['lat' => 26.1446, 'lng' => 91.7363, 'accuracy_m' => 8, 'app_version' => '0.1.0'],
    ];
}

function twoPhotos($test, $field, Work $work): array
{
    return [uploadPhoto($test, $field, $work), uploadPhoto($test, $field, $work)];
}

it('records photo distance from the site using PostGIS', function () {
    [$work, $field] = workWithFieldUser();

    $this->actingAs($field, 'sanctum')->post('/api/v1/photos', [
        'client_uuid' => (string) Str::uuid(), 'work_id' => $work->id, 'file' => fakeJpeg(),
        'lat' => 26.1445, 'lng' => 91.7462, // ~1 km east of the site
        'taken_at' => now()->toIso8601String(),
    ], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('distance_from_site_m', fn ($d) => $d > 950 && $d < 1050);
});

it('stores UTC times from the field app as the same instant', function () {
    [$work, $field] = workWithFieldUser();
    $taken = now()->subMinutes(10)->startOfSecond();
    $uuid = (string) Str::uuid();

    $this->actingAs($field, 'sanctum')->post('/api/v1/photos', [
        'client_uuid' => $uuid, 'work_id' => $work->id, 'file' => fakeJpeg(),
        'lat' => 26.1446, 'lng' => 91.7363, 'taken_at' => $taken->copy()->utc()->format('Y-m-d\TH:i:s.v\Z'),
    ], ['Accept' => 'application/json'])->assertCreated();
    $photo2 = uploadPhoto($this, $field, $work);

    $this->actingAs($field, 'sanctum')->postJson('/api/v1/progress-updates', updatePayload($work, [$uuid, $photo2], [
        'captured_at' => $taken->copy()->utc()->format('Y-m-d\TH:i:s.v\Z'),
    ]))->assertCreated()->assertJsonPath('rule_results', []);

    $update = ProgressUpdate::query()->latest('id')->first();
    expect($update->captured_at->equalTo($taken))->toBeTrue()
        ->and($update->photos()->where('client_uuid', $uuid)->first()->taken_at->equalTo($taken))->toBeTrue();
});

it('puts a clean update in the DPC queue as unflagged', function () {
    [$work, $field] = workWithFieldUser();

    $this->actingAs($field, 'sanctum')->postJson('/api/v1/progress-updates', updatePayload($work, twoPhotos($this, $field, $work)))
        ->assertCreated()
        ->assertJsonPath('status', 'pending_approval')
        ->assertJsonPath('flagged', false)
        ->assertJsonPath('rule_results', []);

    // Not accepted yet, so the work record is unchanged.
    expect($work->fresh()->physical_pct)->toBe(0.0);
});

it('rejects spend above release (R01) with a localised message, keeping photos for resubmission', function () {
    [$work, $field] = workWithFieldUser();
    $photos = twoPhotos($this, $field, $work);

    $this->actingAs($field, 'sanctum')
        ->postJson('/api/v1/progress-updates', updatePayload($work, $photos, ['spent_cumulative' => 60_000_00]), ['Accept-Language' => 'as'])
        ->assertStatus(422)
        ->assertJsonPath('status', 'rejected')
        ->assertJsonPath('rule_results.0.code', 'R01')
        ->assertJsonPath('rule_results.0.message', fn ($m) => str_contains($m, 'খৰচ'));

    $this->actingAs($field, 'sanctum')->postJson('/api/v1/progress-updates', updatePayload($work, $photos))->assertCreated();
});

it('flags photos taken away from the site (R03)', function () {
    [$work, $field] = workWithFieldUser();
    $photos = [uploadPhoto($this, $field, $work, ['lat' => 26.20, 'lng' => 91.80]), uploadPhoto($this, $field, $work)];

    $this->actingAs($field, 'sanctum')->postJson('/api/v1/progress-updates', updatePayload($work, $photos))
        ->assertCreated()
        ->assertJsonPath('flagged', true)
        ->assertJsonPath('rule_results.0.code', 'R03');
});

it('is idempotent on client_uuid', function () {
    [$work, $field] = workWithFieldUser();
    $payload = updatePayload($work, twoPhotos($this, $field, $work));

    $this->actingAs($field, 'sanctum')->postJson('/api/v1/progress-updates', $payload)->assertCreated();
    $this->actingAs($field, 'sanctum')->postJson('/api/v1/progress-updates', $payload)->assertOk();

    expect(ProgressUpdate::withoutGlobalScopes()->count())->toBe(1);
});

it('returns 409 when photos are not uploaded yet', function () {
    [$work, $field] = workWithFieldUser();

    $this->actingAs($field, 'sanctum')
        ->postJson('/api/v1/progress-updates', updatePayload($work, [(string) Str::uuid()]))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'PHOTOS_NOT_UPLOADED');
});

it('updates the work only when the DPC approves', function () {
    [$work, $field] = workWithFieldUser();
    $dpc = userWithRole(Role::DPC, ['level' => 'district', 'district_id' => $work->district_id]);
    $id = $this->actingAs($field, 'sanctum')
        ->postJson('/api/v1/progress-updates', updatePayload($work, twoPhotos($this, $field, $work)))
        ->json('id');

    $this->actingAs($dpc, 'sanctum')->getJson('/api/v1/approvals?tab=clean')
        ->assertOk()->assertJsonPath('data.0.id', $id);

    $this->actingAs($dpc, 'sanctum')->postJson("/api/v1/approvals/{$id}/approve")
        ->assertOk()->assertJsonPath('status', 'accepted');

    $work->refresh();
    expect($work->physical_pct)->toBe(20.0)
        ->and($work->spent_total)->toBe(20_000_00)
        ->and($work->status)->toBe(Work::STATUS_IN_PROGRESS)
        ->and($work->last_accepted_update_at)->not->toBeNull();

    // R02 now checks against the accepted baseline.
    $this->actingAs($field, 'sanctum')
        ->postJson('/api/v1/progress-updates', updatePayload($work, twoPhotos($this, $field, $work), ['physical_pct' => 10]))
        ->assertStatus(422)
        ->assertJsonPath('rule_results.0.code', 'R02');
});

it('needs a note to approve a flagged update, and bulk-approves only clean ones', function () {
    [$work, $field] = workWithFieldUser();
    $dpc = userWithRole(Role::DPC, ['level' => 'district', 'district_id' => $work->district_id]);
    $photos = [uploadPhoto($this, $field, $work, ['lat' => 26.3, 'lng' => 91.9]), uploadPhoto($this, $field, $work)];
    $flaggedId = $this->actingAs($field, 'sanctum')->postJson('/api/v1/progress-updates', updatePayload($work, $photos))->json('id');

    $this->actingAs($dpc, 'sanctum')->postJson('/api/v1/approvals/bulk-approve', ['ids' => [$flaggedId]])
        ->assertOk()->assertJsonPath('approved', 0);
    $this->actingAs($dpc, 'sanctum')->postJson("/api/v1/approvals/{$flaggedId}/approve")->assertStatus(422);
    $this->actingAs($dpc, 'sanctum')->postJson("/api/v1/approvals/{$flaggedId}/return", ['note' => 'Retake photos at site'])
        ->assertOk()->assertJsonPath('status', 'returned');
});

it('does not let a field user open the approval queue', function () {
    [, $field] = workWithFieldUser();

    $this->actingAs($field, 'sanctum')->getJson('/api/v1/approvals')->assertForbidden();
});

it('writes an audit trail entry for an approval', function () {
    [$work, $field] = workWithFieldUser();
    $dpc = userWithRole(Role::DPC, ['level' => 'district', 'district_id' => $work->district_id]);
    $id = $this->actingAs($field, 'sanctum')
        ->postJson('/api/v1/progress-updates', updatePayload($work, twoPhotos($this, $field, $work)))
        ->json('id');
    $this->actingAs($dpc, 'sanctum')->postJson("/api/v1/approvals/{$id}/approve")->assertOk();

    $log = Activity::where('subject_type', ProgressUpdate::class)->where('subject_id', $id)->latest('id')->first();
    expect($log->causer_id)->toBe($dpc->id)
        ->and($log->properties['attributes']['status'])->toBe('accepted');
});
