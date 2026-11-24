<?php

namespace App\Domain\Grievances;

use App\Domain\Auth\OtpSender;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Proves a citizen holds the mobile they gave, before a grievance is filed. Kept apart from staff login OTPs
 * (otp_codes), so a citizen code can never sign anyone in and a staff code is never consumed here.
 */
class CitizenOtp
{
    public function __construct(private readonly OtpSender $sender) {}

    /** False when this mobile asked too often (3 codes per 15 minutes). */
    public function send(string $mobile, string $locale = 'en'): bool
    {
        $key = 'grievance-otp-request:'.$mobile;
        if (RateLimiter::tooManyAttempts($key, 3)) {
            return false;
        }
        RateLimiter::hit($key, 15 * 60);

        $length = (int) config('dcp.otp.length', 6);
        $code = str_pad((string) random_int(0, 10 ** $length - 1), $length, '0', STR_PAD_LEFT);
        Cache::put($this->key($mobile), ['hash' => Hash::make($code), 'attempts' => 0], now()->addMinutes((int) config('dcp.otp.ttl_minutes', 5)));
        $this->sender->send($mobile, $code, $locale);

        return true;
    }

    public function verify(string $mobile, string $code): bool
    {
        $entry = Cache::get($this->key($mobile));
        if (! $entry || $entry['attempts'] >= (int) config('dcp.otp.max_attempts', 5)) {
            return false;
        }
        if (! Hash::check($code, $entry['hash'])) {
            $entry['attempts']++;
            Cache::put($this->key($mobile), $entry, now()->addMinutes((int) config('dcp.otp.ttl_minutes', 5)));

            return false;
        }
        Cache::forget($this->key($mobile));

        return true;
    }

    private function key(string $mobile): string
    {
        return 'grievance-otp:'.$mobile;
    }
}
