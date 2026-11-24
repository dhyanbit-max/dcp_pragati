<?php

namespace App\Domain\Auth;

use Illuminate\Support\Facades\Log;
use RuntimeException;

/** Development only: writes the OTP to the log instead of sending an SMS. */
class LogOtpSender implements OtpSender
{
    public function send(string $mobile, string $code, string $locale = 'en'): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('LogOtpSender must not be used in production. Configure DCP_OTP_DRIVER.');
        }
        Log::info("DCP Pragati OTP for {$mobile}: {$code}");
        DevOtp::remember($mobile, $code);
    }
}
