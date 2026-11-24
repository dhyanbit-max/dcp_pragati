<?php

use App\Domain\Access\Role;
use App\Domain\Auth\OtpSender;

/** Captures OTPs instead of sending them. */
class CapturingOtpSender implements OtpSender
{
    public array $sent = [];

    public function send(string $mobile, string $code, string $locale = 'en'): void
    {
        $this->sent[$mobile] = $code;
    }
}

beforeEach(function () {
    $this->otp = new CapturingOtpSender;
    app()->instance(OtpSender::class, $this->otp);
});

it('logs in a field user with OTP and returns a working token', function () {
    $user = userWithRole(Role::FIELD_USER);

    $this->postJson('/api/v1/auth/otp/request', ['mobile' => $user->mobile])->assertStatus(202);
    $code = $this->otp->sent[$user->mobile];

    $token = $this->postJson('/api/v1/auth/otp/verify', ['mobile' => $user->mobile, 'otp' => $code, 'device_id' => 'test-device'])
        ->assertOk()->assertJsonPath('user.roles.0', Role::FIELD_USER)->json('token');

    $this->withToken($token)->getJson('/api/v1/me')->assertOk()->assertJsonPath('id', $user->id);
});

it('answers the same for unknown numbers and sends nothing', function () {
    $this->postJson('/api/v1/auth/otp/request', ['mobile' => '9876543210'])->assertStatus(202);

    expect($this->otp->sent)->toBe([]);
});

it('locks the code after too many wrong attempts', function () {
    $user = userWithRole(Role::FIELD_USER);
    $this->postJson('/api/v1/auth/otp/request', ['mobile' => $user->mobile]);
    $code = $this->otp->sent[$user->mobile];
    $wrong = $code === '000000' ? '111111' : '000000';

    foreach (range(1, 5) as $_) {
        $this->postJson('/api/v1/auth/otp/verify', ['mobile' => $user->mobile, 'otp' => $wrong, 'device_id' => 'd'])->assertStatus(422);
    }

    $this->postJson('/api/v1/auth/otp/verify', ['mobile' => $user->mobile, 'otp' => $code, 'device_id' => 'd'])->assertStatus(422);
});
