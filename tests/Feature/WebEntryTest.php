<?php

use App\Domain\Access\Role;
use App\Livewire\Progress\EntryForm;
use App\Livewire\Works\Show;
use App\Models\ProgressUpdate;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    [$this->work] = workWithFieldUser();
    $this->dpc = userWithRole(Role::DPC, ['level' => 'district', 'district_id' => $this->work->district_id]);
});

function photoUploads(int $n): array
{
    return array_map(fn () => UploadedFile::fake()->image("p{$n}.jpg", 64, 48), range(1, $n));
}

it('sends a web update to the DPC, always flagged because photos were uploaded (R12)', function () {
    $this->actingAs($this->dpc);

    Livewire::test(EntryForm::class, ['work' => $this->work])
        ->set('stageItemId', stageId($this->work, 2))
        ->set('physicalPct', '20')
        ->set('spentRupees', '20000')
        ->set('photos', photoUploads(2))
        ->call('submit')
        ->assertHasNoErrors()
        ->assertRedirect(route('works.show', $this->work));

    $u = ProgressUpdate::withoutGlobalScopes()->sole();
    expect($u->channel)->toBe('web')
        ->and($u->status)->toBe(ProgressUpdate::STATUS_PENDING)
        ->and($u->is_flagged)->toBeTrue()
        ->and($u->ruleResults()->where('outcome', 'flag')->pluck('rule_code')->all())->toContain('R12')
        ->and($u->spent_cumulative)->toBe(20_000_00);
});

it('shows the reasons and keeps uploaded photos when an update is rejected', function () {
    $this->actingAs($this->dpc);

    $c = Livewire::test(EntryForm::class, ['work' => $this->work])
        ->set('stageItemId', stageId($this->work, 2))
        ->set('physicalPct', '20')
        ->set('spentRupees', '60000') // more than the ₹50,000 released
        ->set('photos', photoUploads(2))
        ->call('submit')
        ->assertNoRedirect()
        ->assertSee('R01');

    expect($c->get('storedPhotoUuids'))->toHaveCount(2);

    $c->set('spentRupees', '20000')->call('submit')->assertRedirect(route('works.show', $this->work));
    expect(ProgressUpdate::withoutGlobalScopes()->where('status', 'pending_approval')->sole()->photos()->withoutGlobalScopes()->count())->toBe(2);
});

it('records a fund release and refuses one above the sanction', function () {
    $this->actingAs($this->dpc);

    Livewire::test(Show::class, ['work' => $this->work])
        ->call('openReleaseForm')
        ->set('releaseRupees', '60000') // sanctioned ₹1,00,000, ₹50,000 already released
        ->call('recordRelease')
        ->assertHasErrors('releaseRupees')
        ->set('releaseRupees', '25000')
        ->call('recordRelease')
        ->assertHasNoErrors();

    expect($this->work->fresh()->released_total)->toBe(75_000_00);
});

it('does not let a state viewer record releases', function () {
    $this->actingAs(userWithRole(Role::STATE_VIEWER));

    Livewire::test(Show::class, ['work' => $this->work])->call('openReleaseForm')->assertForbidden();
});
