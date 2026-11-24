<?php

use App\Domain\Access\Role;
use App\Domain\Auth\OtpSender;
use App\Models\District;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->otp = new class implements OtpSender
    {
        public array $sent = [];

        public function send(string $mobile, string $code, string $locale = 'en'): void
        {
            $this->sent[$mobile] = $code;
        }
    };
    app()->instance(OtpSender::class, $this->otp);

    $d = District::create(['lgd_code' => 'P1', 'name_en' => 'P district']);
    // As created by the user import: no password.
    $this->dpc = userWithRole(Role::DPC, ['level' => 'district', 'district_id' => $d->id]);
    $this->dpc->forceFill(['password' => null])->save();
});

it('lets an imported web user set a password with an OTP, then sign in', function () {
    $this->post('/password/setup', ['mobile' => $this->dpc->mobile])->assertRedirect(route('password.setup.verify'));
    $this->get(route('password.setup.verify'))->assertOk()->assertSee(substr($this->dpc->mobile, -4));

    $this->post('/password/setup/verify', [
        'otp' => $this->otp->sent[$this->dpc->mobile],
        'password' => 'district-2026',
        'password_confirmation' => 'district-2026',
    ])->assertRedirect(route('login'))->assertSessionHas('status');

    $this->assertGuest();
    $fresh = $this->dpc->fresh();
    expect(Hash::check('district-2026', $fresh->password))->toBeTrue()
        ->and($fresh->last_login_at)->toBeNull()
        ->and(Activity::where('event', 'password_set')->where('subject_id', $this->dpc->id)->exists())->toBeTrue();

    // The normal password + OTP login now works.
    $this->post('/login', ['mobile' => $this->dpc->mobile, 'password' => 'district-2026'])->assertRedirect(route('login.otp'));
    $this->post('/login/otp', ['otp' => $this->otp->sent[$this->dpc->mobile]])->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($fresh);
});

it('rejects a wrong code and a weak or mismatched password', function () {
    $this->post('/password/setup', ['mobile' => $this->dpc->mobile]);
    $code = $this->otp->sent[$this->dpc->mobile];
    $wrong = $code === '000000' ? '111111' : '000000';

    $this->post('/password/setup/verify', ['otp' => $wrong, 'password' => 'district-2026', 'password_confirmation' => 'district-2026'])
        ->assertSessionHasErrors('otp');
    $this->post('/password/setup/verify', ['otp' => $code, 'password' => 'short1', 'password_confirmation' => 'short1'])
        ->assertSessionHasErrors('password');
    $this->post('/password/setup/verify', ['otp' => $code, 'password' => 'district-2026', 'password_confirmation' => 'district-2027'])
        ->assertSessionHasErrors('password');

    expect($this->dpc->fresh()->password)->toBeNull();
});

it('answers the same for unknown numbers and field users, and sends them no code', function (string $who) {
    $mobile = $who === 'unknown' ? '9123456780' : userWithRole(Role::FIELD_USER)->mobile;

    $this->post('/password/setup', ['mobile' => $mobile])->assertRedirect(route('password.setup.verify'));
    expect($this->otp->sent)->toBe([]);

    $this->post('/password/setup/verify', ['otp' => '123456', 'password' => 'district-2026', 'password_confirmation' => 'district-2026'])
        ->assertSessionHasErrors('otp');
})->with(['unknown', 'field user']);

it('does not let a field user turn an app OTP into a web password', function () {
    $field = userWithRole(Role::FIELD_USER);
    $this->postJson('/api/v1/auth/otp/request', ['mobile' => $field->mobile])->assertSuccessful();
    $this->withSession(['password_setup.mobile' => $field->mobile])
        ->post('/password/setup/verify', ['otp' => $this->otp->sent[$field->mobile], 'password' => 'district-2026', 'password_confirmation' => 'district-2026'])
        ->assertSessionHasErrors('otp');

    expect(User::find($field->id)->password)->not->toBeNull()->and(Hash::check('district-2026', User::find($field->id)->password))->toBeFalse();
});

it('sends people without a pending mobile back to the start', function () {
    $this->get(route('password.setup.verify'))->assertRedirect(route('password.setup'));
    $this->get(route('login'))->assertSee(route('password.setup'));
});
