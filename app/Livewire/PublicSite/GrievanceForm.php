<?php

namespace App\Livewire\PublicSite;

use App\Domain\Grievances\CitizenOtp;
use App\Domain\Grievances\GrievanceService;
use App\Models\CoDistrict;
use App\Models\District;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/** Citizen grievance: details → OTP on the mobile → reference number (docs/20 §2). */
#[Layout('components.layouts.public')]
class GrievanceForm extends Component
{
    use WithFileUploads;

    #[Locked]
    public string $step = 'details'; // details, verify, done

    public ?int $district = null;

    public ?int $coDistrict = null;

    public string $category = '';

    public string $subject = '';

    public string $description = '';

    public string $workReference = '';

    public string $locationText = '';

    public ?float $lat = null;

    public ?float $lng = null;

    public string $name = '';

    public string $mobile = '';

    public bool $consent = false;

    /** @var TemporaryUploadedFile|null */
    public $photo = null;

    public string $code = '';

    #[Locked]
    public ?string $ref = null;

    public ?string $notice = null;

    protected function rules(): array
    {
        return [
            'district' => ['required', 'integer', 'exists:districts,id'],
            'coDistrict' => ['nullable', 'integer', 'exists:co_districts,id'],
            'category' => ['required', 'in:'.implode(',', config('dcp.grievances.categories'))],
            'subject' => ['required', 'string', 'min:5', 'max:150'],
            'description' => ['required', 'string', 'min:20', 'max:2000'],
            'workReference' => ['nullable', 'string', 'max:120'],
            'locationText' => ['nullable', 'string', 'max:255'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'name' => ['nullable', 'string', 'max:120'],
            'mobile' => ['required', 'regex:/^[6-9]\d{9}$/'],
            'consent' => ['accepted'],
            'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.(int) config('dcp.grievances.max_photo_kb')],
        ];
    }

    protected function messages(): array
    {
        return [
            'mobile.regex' => __('Enter a 10-digit mobile number.'),
            'consent.accepted' => __('Please agree so that we can contact you about this grievance.'),
        ];
    }

    public function updatedDistrict(): void
    {
        $this->coDistrict = null;
    }

    public function sendCode(CitizenOtp $otp): void
    {
        $this->mobile = preg_replace('/\D/', '', $this->mobile) ?? '';
        if (strlen($this->mobile) === 12 && str_starts_with($this->mobile, '91')) {
            $this->mobile = substr($this->mobile, 2);
        }
        $this->validate();

        $ipKey = 'grievance-ip:'.request()->ip();
        if (RateLimiter::tooManyAttempts($ipKey, 10)) {
            $this->addError('mobile', __('Too many requests from this connection. Try again in an hour.'));

            return;
        }
        RateLimiter::hit($ipKey, 3600);

        if (! $otp->send($this->mobile, app()->getLocale())) {
            $this->addError('mobile', __('Too many codes sent to this number. Try again in 15 minutes.'));

            return;
        }
        $this->code = '';
        $this->notice = __('We sent a 6-digit code to :m.', ['m' => $this->maskedMobile()]);
        $this->step = 'verify';
    }

    public function resend(CitizenOtp $otp): void
    {
        $this->notice = $otp->send($this->mobile, app()->getLocale())
            ? __('A new code is on its way to :m.', ['m' => $this->maskedMobile()])
            : __('Too many codes sent to this number. Try again in 15 minutes.');
    }

    public function edit(): void
    {
        $this->step = 'details';
        $this->notice = null;
    }

    public function verify(CitizenOtp $otp, GrievanceService $service): void
    {
        $this->validate(['code' => ['required', 'digits:'.(int) config('dcp.otp.length', 6)]]);
        $this->validate(); // the details again: the browser may have changed them since the code was sent

        if (! $otp->verify($this->mobile, $this->code)) {
            $this->addError('code', __('That code is wrong or has expired.'));

            return;
        }

        $grievance = $service->submit([
            'district_id' => $this->district,
            'co_district_id' => $this->coDistrict,
            'category' => $this->category,
            'subject' => trim($this->subject),
            'description' => trim($this->description),
            'work_reference' => trim($this->workReference) ?: null,
            'location_text' => trim($this->locationText) ?: null,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'citizen_name' => trim($this->name) ?: null,
            'citizen_mobile' => $this->mobile,
            'citizen_locale' => app()->getLocale(),
        ], $this->photo);

        $this->ref = $grievance->ref_no;
        $this->reset('code', 'photo', 'notice');
        $this->step = 'done';
    }

    private function maskedMobile(): string
    {
        return substr($this->mobile, 0, 2).'XXXXX'.substr($this->mobile, -3);
    }

    public function render()
    {
        $loc = in_array(app()->getLocale(), ['as', 'bn'], true) ? app()->getLocale() : 'en';

        return view('livewire.public-site.grievance-form', [
            'districts' => District::orderBy('name_en')->get(['id', 'name_en', "name_$loc"]),
            'coDistricts' => $this->district ? CoDistrict::where('district_id', $this->district)->orderBy('name_en')->get(['id', 'name_en', "name_$loc"]) : collect(),
            'loc' => $loc,
        ])->title(__('Raise a grievance'));
    }
}
