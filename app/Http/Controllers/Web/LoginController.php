<?php

namespace App\Http\Controllers\Web;

use App\Domain\Access\Permission;
use App\Domain\Auth\OtpService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Web login: mobile + password, then an OTP second factor (docs/13 §2).
 * Field users without a password use the app only.
 */
class LoginController extends Controller
{
    private const PENDING = 'login.pending_user_id';

    public function __construct(private readonly OtpService $otp) {}

    public function show(): View
    {
        return view('auth.login');
    }

    public function attempt(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'mobile' => ['required', 'regex:/^[6-9]\d{9}$/'],
            'password' => ['required', 'string'],
        ]);

        $key = 'web-login:'.$data['mobile'].'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['mobile' => __('auth.throttle', ['seconds' => RateLimiter::availableIn($key)])]);
        }

        $user = User::where('mobile', $data['mobile'])->where('is_active', true)->first();
        if (! $user || ! $user->password || ! Hash::check($data['password'], $user->password)
            || ! $user->can(Permission::VIEW_DASHBOARD)) {
            RateLimiter::hit($key, 15 * 60);
            throw ValidationException::withMessages(['mobile' => __('auth.failed')]);
        }

        RateLimiter::clear($key);
        $request->session()->put(self::PENDING, $user->id);
        $this->otp->request($user->mobile);

        return redirect()->route('login.otp');
    }

    public function showOtp(Request $request): View|RedirectResponse
    {
        $user = User::find($request->session()->get(self::PENDING));
        if (! $user) {
            return redirect()->route('login');
        }

        return view('auth.otp', [
            'maskedMobile' => str_repeat('•', 6).substr($user->mobile, -4),
            'devOtp' => \App\Domain\Auth\DevOtp::peek($user->mobile),
        ]);
    }

    public function verifyOtp(Request $request): RedirectResponse
    {
        $data = $request->validate(['otp' => ['required', 'digits:'.config('dcp.otp.length', 6)]]);
        $user = User::find($request->session()->get(self::PENDING));
        if (! $user) {
            return redirect()->route('login');
        }

        if (! $this->otp->verify($user->mobile, $data['otp'])) {
            throw ValidationException::withMessages(['otp' => __('The code is wrong or has expired.')]);
        }

        $request->session()->forget(self::PENDING);
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('locale', $user->preferred_locale);

        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    public function setLocale(Request $request): RedirectResponse
    {
        $data = $request->validate(['locale' => ['required', 'in:'.implode(',', config('dcp.locales'))]]);
        $request->session()->put('locale', $data['locale']);
        $request->user()?->forceFill(['preferred_locale' => $data['locale']])->save();

        return back();
    }
}
