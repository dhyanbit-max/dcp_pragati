<?php

namespace App\Domain\Auth;

use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/** Mobile OTP login (docs/12-api-spec.md §1). */
class OtpService
{
    public function __construct(private readonly OtpSender $sender) {}

    /** Always "succeeds" from the caller's view, so mobile numbers cannot be enumerated. */
    public function request(string $mobile): void
    {
        $key = 'otp-request:'.$mobile;
        if (RateLimiter::tooManyAttempts($key, 3)) {
            return;
        }
        RateLimiter::hit($key, 15 * 60);

        $user = User::where('mobile', $mobile)->where('is_active', true)->first();
        if (! $user) {
            return;
        }

        $length = (int) config('dcp.otp.length', 6);
        $code = str_pad((string) random_int(0, 10 ** $length - 1), $length, '0', STR_PAD_LEFT);

        OtpCode::where('mobile', $mobile)->whereNull('consumed_at')->update(['consumed_at' => now()]);
        OtpCode::create([
            'mobile' => $mobile,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes((int) config('dcp.otp.ttl_minutes', 5)),
        ]);

        $this->sender->send($mobile, $code, $user->preferred_locale);
    }

    /** @param bool $markLogin false when the code proves ownership of the mobile without signing in (password setup). */
    public function verify(string $mobile, string $code, bool $markLogin = true): ?User
    {
        $otp = OtpCode::where('mobile', $mobile)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->latest('id')
            ->first();

        if (! $otp || $otp->attempts >= (int) config('dcp.otp.max_attempts', 5)) {
            return null;
        }

        if (! Hash::check($code, $otp->code_hash)) {
            $otp->increment('attempts');

            return null;
        }

        $otp->update(['consumed_at' => now()]);
        $user = User::where('mobile', $mobile)->where('is_active', true)->first();
        if ($markLogin) {
            $user?->forceFill(['last_login_at' => now()])->save();
        }

        return $user;
    }
}
