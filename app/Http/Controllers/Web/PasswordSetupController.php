<?php

namespace App\Http\Controllers\Web;

use App\Domain\Access\Permission;
use App\Domain\Auth\OtpService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Set or reset a web password with a mobile OTP. Imported users have no password (passwords are
 * never imported), so this is also how they first get one. Responses are the same whether or not
 * the mobile belongs to a web user, so numbers cannot be enumerated.
 */
class PasswordSetupController extends Controller
{
    private const MOBILE = 'password_setup.mobile';

    public function __construct(private readonly OtpService $otp) {}

    public function show(): View
    {
        return view('auth.password-request');
    }

    public function sendCode(Request $request): RedirectResponse
    {
        $data = $request->validate(['mobile' => ['required', 'regex:/^[6-9]\d{9}$/']]);

        if ($this->eligible($data['mobile'])) {
            $this->otp->request($data['mobile']); // rate-limited per mobile inside the service
        }
        $request->session()->put(self::MOBILE, $data['mobile']);

        return redirect()->route('password.setup.verify');
    }

    public function showVerify(Request $request): View|RedirectResponse
    {
        $mobile = $request->session()->get(self::MOBILE);
        if (! $mobile) {
            return redirect()->route('password.setup');
        }

        return view('auth.password-set', [
            'maskedMobile' => str_repeat('•', 6).substr($mobile, -4),
            'devOtp' => \App\Domain\Auth\DevOtp::peek($mobile),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $mobile = $request->session()->get(self::MOBILE);
        if (! $mobile) {
            return redirect()->route('password.setup');
        }

        $data = $request->validate([
            'otp' => ['required', 'digits:'.config('dcp.otp.length', 6)],
            'password' => ['required', 'confirmed', Password::min(10)->letters()->numbers()],
        ]);

        // Check eligibility before spending the code, and give the same answer as a wrong code.
        $user = $this->eligible($mobile) ? $this->otp->verify($mobile, $data['otp'], markLogin: false) : null;
        if (! $user) {
            throw ValidationException::withMessages(['otp' => __('The code is wrong or has expired.')]);
        }

        $user->forceFill(['password' => $data['password'], 'remember_token' => Str::random(60)])->save();
        activity()->performedOn($user)->causedBy($user)->event('password_set')
            ->withProperties(['ip' => $request->ip()])
            ->log('Password set with OTP');

        $request->session()->forget(self::MOBILE);

        return redirect()->route('login')->with('status', __('Your password is set. Sign in with it now.'));
    }

    private function eligible(string $mobile): bool
    {
        $user = User::where('mobile', $mobile)->where('is_active', true)->first();

        return (bool) $user?->can(Permission::VIEW_DASHBOARD);
    }
}
