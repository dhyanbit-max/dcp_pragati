<?php

namespace App\Domain\Auth;

use Illuminate\Support\Facades\Cache;

/**
 * Development aid: the last OTP sent to a mobile, shown on the form that asks for it, so testers need not read
 * the log. Only when codes go to the log (driver "log") and never in production; a real SMS gateway turns it off.
 */
final class DevOtp
{
    public static function enabled(): bool
    {
        return (bool) config('dcp.otp.show_on_screen')
            && config('dcp.otp.driver') === 'log'
            && ! app()->isProduction();
    }

    public static function remember(string $mobile, string $code): void
    {
        if (self::enabled()) {
            Cache::put(self::key($mobile), $code, now()->addMinutes((int) config('dcp.otp.ttl_minutes', 5)));
        }
    }

    public static function peek(?string $mobile): ?string
    {
        return $mobile && self::enabled() ? Cache::get(self::key($mobile)) : null;
    }

    private static function key(string $mobile): string
    {
        return 'dev-otp:'.$mobile;
    }
}
