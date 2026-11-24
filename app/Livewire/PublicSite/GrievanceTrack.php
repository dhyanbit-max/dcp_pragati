<?php

namespace App\Livewire\PublicSite;

use App\Models\Grievance;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Citizen tracking: reference number + the mobile it was filed with. Shows status, deadlines and the public
 * timeline only: never officer names, internal notes or other personal data. 10 tries per 10 minutes per connection.
 */
#[Layout('components.layouts.public')]
class GrievanceTrack extends Component
{
    #[Url]
    public string $ref = '';

    public string $mobile = '';

    #[Locked]
    public ?int $grievanceId = null;

    public function find(): void
    {
        $this->ref = strtoupper(trim($this->ref));
        $this->mobile = substr(preg_replace('/\D/', '', $this->mobile) ?? '', -10);
        $this->validate([
            'ref' => ['required', 'regex:/^GRV-\d{2}-\d{6}$/'],
            'mobile' => ['required', 'digits:10'],
        ], ['ref.regex' => __('A reference looks like GRV-26-000123.')]);

        $key = 'grievance-track:'.request()->ip();
        if (RateLimiter::tooManyAttempts($key, 10)) {
            $this->addError('ref', __('Too many tries. Wait a few minutes and try again.'));

            return;
        }
        RateLimiter::hit($key, 600);

        $g = Grievance::withoutGlobalScopes()->where('ref_no', $this->ref)->where('citizen_mobile', $this->mobile)->first();
        if (! $g) {
            $this->grievanceId = null;
            $this->addError('ref', __('No grievance matches this reference and mobile number.'));

            return;
        }
        $this->grievanceId = $g->id;
    }

    public function again(): void
    {
        $this->reset('grievanceId', 'mobile');
    }

    public function render()
    {
        $g = $this->grievanceId
            ? Grievance::withoutGlobalScopes()->with(['district', 'coDistrict', 'events' => fn ($q) => $q->where('is_public', true)])->find($this->grievanceId)
            : null;

        return view('livewire.public-site.grievance-track', ['g' => $g])->title(__('Track a grievance'));
    }
}
