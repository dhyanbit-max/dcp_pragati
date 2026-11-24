<?php

namespace App\Domain\Auth;

interface OtpSender
{
    public function send(string $mobile, string $code, string $locale = 'en'): void;
}
