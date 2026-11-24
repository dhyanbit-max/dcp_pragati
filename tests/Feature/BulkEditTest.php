<?php

use App\Domain\Access\Role;
use App\Livewire\Progress\BulkEditor;
use App\Models\District;
use App\Models\ProgressUpdate;
use App\Models\Work;
use Livewire\Livewire;

beforeEach(function () {
    [$this->work] = workWithFieldUser();
    $this->dpc = userWithRole(Role::DPC, ['level' => 'district', 'district_id' => $this->work->district_id]);
});

it('creates one bulk update per changed row, through the rules engine', function () {
    [$other] = workWithFieldUser(['district_id' => $this->work->district_id]);
    $this->actingAs($this->dpc);

    Livewire::test(BulkEditor::class)
        ->assertSee($this->work->code)
        ->assertSee($other->code)
        ->set("rows.{$this->work->id}.spent", '20000') // spend only: no photos needed
        ->call('save')
        ->assertHasNoErrors();

    $u = ProgressUpdate::withoutGlobalScopes()->sole();
    expect($u->work_id)->toBe($this->work->id)
        ->and($u->channel)->toBe('bulk')
        ->and($u->status)->toBe(ProgressUpdate::STATUS_PENDING)
        ->and($u->spent_cumulative)->toBe(20_000_00)
        ->and($this->work->fresh()->spent_total)->toBe(0); // approved figures untouched until the DPC approves
});

it('shows rule reasons for a rejected row and keeps the figures for correction', function () {
    $this->actingAs($this->dpc);

    $c = Livewire::test(BulkEditor::class)
        ->set("rows.{$this->work->id}.spent", '60000') // more than the ₹50,000 released (R01)
        ->call('save')
        ->assertSee('R01');

    expect($c->get("results.{$this->work->id}.status"))->toBe(ProgressUpdate::STATUS_REJECTED)
        ->and($c->get("rows.{$this->work->id}.spent"))->toBe('60000');

    $c->set("rows.{$this->work->id}.spent", '30000')->call('save');
    expect(ProgressUpdate::withoutGlobalScopes()->pluck('status')->sort()->values()->all())
        ->toBe([ProgressUpdate::STATUS_PENDING, ProgressUpdate::STATUS_REJECTED]);
});

it('does not submit unchanged rows', function () {
    $this->actingAs($this->dpc);

    Livewire::test(BulkEditor::class)->call('save')->assertHasErrors('rows');
    expect(ProgressUpdate::withoutGlobalScopes()->count())->toBe(0);
});

it('ignores rows for works outside the user\'s jurisdiction', function () {
    $other = District::create(['lgd_code' => 'T2', 'name_en' => 'Test District 2']);
    $foreign = Work::factory()->inDistrict($other)->create();
    $this->actingAs($this->dpc);

    Livewire::test(BulkEditor::class)
        ->assertDontSee($foreign->code)
        ->set("rows.{$foreign->id}", ['stage' => $foreign->current_stage_item_id, 'pct' => '0', 'spent' => '1000'])
        ->call('save')
        ->assertHasErrors('rows');

    expect(ProgressUpdate::withoutGlobalScopes()->count())->toBe(0);
});

it('is limited to roles with bulk edit', function (string $role) {
    $this->actingAs(userWithRole($role, ['level' => 'district', 'district_id' => $this->work->district_id]));

    $this->get(route('bulk-edit'))->assertForbidden();
})->with([Role::CO_DISTRICT_OFFICER, Role::DISTRICT_COLLECTOR, Role::STATE_VIEWER]);

it('opens for the DPC', function () {
    $this->actingAs($this->dpc)->get(route('bulk-edit'))->assertOk()->assertSee($this->work->code);
});
