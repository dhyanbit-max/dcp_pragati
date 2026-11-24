<?php

use App\Domain\Access\Role;
use App\Models\District;
use App\Models\Work;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->districtA = District::create(['lgd_code' => 'JA', 'name_en' => 'A']);
    $this->districtB = District::create(['lgd_code' => 'JB', 'name_en' => 'B']);
    [$this->workA, $this->fieldA] = workWithFieldUser(['district_id' => $this->districtA->id]);
    [$this->workB, $this->fieldB] = workWithFieldUser(['district_id' => $this->districtB->id]);
});

it('limits a DPC to their own district on sync', function () {
    $dpcA = userWithRole(Role::DPC, ['level' => 'district', 'district_id' => $this->districtA->id]);

    $ids = collect($this->actingAs($dpcA, 'sanctum')->getJson('/api/v1/sync/pull')->assertOk()->json('works'))->pluck('id');

    expect($ids->all())->toBe([$this->workA->id]);
});

it('limits a field user to assigned works', function () {
    $ids = collect($this->actingAs($this->fieldB, 'sanctum')->getJson('/api/v1/sync/pull')->json('works'))->pluck('id');

    expect($ids->all())->toBe([$this->workB->id]);
});

it('sends the app the stale threshold the dashboards use', function () {
    config(['dcp.monitoring.stale_days' => 10]);

    $this->actingAs($this->fieldB, 'sanctum')->getJson('/api/v1/sync/pull')
        ->assertOk()->assertJsonPath('app_config.stale_days', 10);
});

it('gives state roles every work', function () {
    $this->actingAs(userWithRole(Role::STATE_VIEWER), 'sanctum');

    expect(Work::query()->count())->toBe(2);
});

it('gives a user with no jurisdiction rows nothing', function () {
    $this->actingAs(userWithRole(Role::DPC), 'sanctum');

    expect(Work::query()->count())->toBe(0);
});

it('stops a field user uploading for a work outside their assignment', function () {
    Storage::fake('local');

    $this->actingAs($this->fieldA, 'sanctum')->postJson('/api/v1/photos', [
        'client_uuid' => (string) Str::uuid(), 'work_id' => $this->workB->id, 'file' => fakeJpeg(),
    ])->assertNotFound();
});

it('hides pending updates of another district from a DPC', function () {
    Storage::fake('local');
    $photos = [uploadPhoto($this, $this->fieldB, $this->workB), uploadPhoto($this, $this->fieldB, $this->workB)];
    $id = $this->actingAs($this->fieldB, 'sanctum')->postJson('/api/v1/progress-updates', [
        'client_uuid' => (string) Str::uuid(), 'work_id' => $this->workB->id,
        'stage_item_id' => stageId($this->workB, 2), 'physical_pct' => 10, 'spent_cumulative' => 10_000_00,
        'captured_at' => now()->toIso8601String(), 'photo_uuids' => $photos,
    ])->assertCreated()->json('id');

    $dpcA = userWithRole(Role::DPC, ['level' => 'district', 'district_id' => $this->districtA->id]);
    $this->actingAs($dpcA, 'sanctum')->getJson('/api/v1/approvals?tab=clean')->assertJsonCount(0, 'data');
    $this->actingAs($dpcA, 'sanctum')->postJson("/api/v1/approvals/{$id}/approve")->assertNotFound();
});
