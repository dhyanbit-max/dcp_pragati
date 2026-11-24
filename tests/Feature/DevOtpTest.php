<?php

use App\Domain\Auth\DevOtp;
use App\Domain\Auth\LogOtpSender;
use App\Domain\Auth\OtpSender;
use App\Domain\Access\Role;

beforeEach(function () {
    config(['dcp.otp.driver' => 'log', 'dcp.otp.show_on_screen' => true]);
    app()->instance(OtpSender::class, new LogOtpSender);
    $this->dpc = userWithRole(Role::DPC);
    $this->dpc->forceFill(['password' => 'secret-pass'])->save();
});

it('shows the sign-in code on the OTP form while codes go to the log', function () {
    $this->post(route('login'), ['mobile' => $this->dpc->mobile, 'password' => 'secret-pass'])->assertRedirect(route('login.otp'));
    $code = DevOtp::peek($this->dpc->mobile);

    expect($code)->toMatch('/^\d{6}$/');
    $this->get(route('login.otp'))->assertOk()->assertSee('Development only')->assertSee($code);
});

it('never shows codes with a real gateway, when switched off, or in production', function (array $config, bool $production) {
    config($config);
    if ($production) {
        app()->detectEnvironment(fn () => 'production');
    }
    DevOtp::remember('9876543210', '123456');

    expect(DevOtp::peek('9876543210'))->toBeNull();
})->with([
    'real gateway' => [['dcp.otp.driver' => 'msg91'], false],
    'switched off' => [['dcp.otp.show_on_screen' => false], false],
    'production' => [[], true],
]);
