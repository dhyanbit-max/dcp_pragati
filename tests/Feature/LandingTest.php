<?php

use App\Domain\Access\Role;
use App\Models\District;
use App\Models\FundSource;
use Database\Seeders\StatesSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    Cache::flush();
    config(['dcp.public.portal' => true, 'dcp.public.grievances' => true]);
});

it('shows guests the landing page with statewide approved totals, and no work names', function () {
    $d = District::create(['lgd_code' => 'LA', 'name_en' => 'Landing A']);
    [$work] = workWithFieldUser(['district_id' => $d->id, 'name_en' => 'Secret road work name']);
    $work->forceFill(['physical_pct' => 40, 'status' => 'in_progress', 'spent_total' => 10_000_00])->save();
    $fund = FundSource::find($work->fund_source_id);

    $this->get('/')
        ->assertOk()
        ->assertSee('Raise a grievance')
        ->assertSee('Works monitored')
        ->assertSee($fund->name_en)
        ->assertDontSee('Secret road work name');
});

it('shows only the introduction when the public portal is off', function () {
    config(['dcp.public.portal' => false]);
    workWithFieldUser();

    $this->get('/')->assertOk()->assertDontSee('Works monitored')->assertSee('How progress is reported');
});

it('draws the Assam map once the state outline is loaded', function () {
    $this->seed(StatesSeeder::class);
    DB::update("UPDATE states SET boundary = ST_Multi(ST_Buffer(ST_SetSRID(ST_MakePoint(92.9, 26.2), 4326), 1.0)) WHERE lgd_code = '18'");

    $this->get('/')->assertOk()->assertSee('<svg viewBox=', false)->assertSee('Map of Assam');
});

it('sends signed-in staff to the dashboard, and field users to the map', function () {
    $this->actingAs(userWithRole(Role::STATE_VIEWER))->get('/')->assertRedirect(route('dashboard'));
    $this->actingAs(userWithRole(Role::FIELD_USER))->get('/')->assertRedirect(route('map'));
});

it('shows the user manual to guests and links it from the landing page', function () {
    $this->get('/')->assertOk()->assertSee(route('manual'));

    $this->get('/manual')
        ->assertOk()
        ->assertSee('User manual')
        ->assertSee('District Planning Cell: approvals')
        ->assertSee('R12')
        ->assertSee(route('grievances.create'));
});

it('lists the demo sign-ins on the login page and manual outside production only', function () {
    $this->get('/login')->assertOk()->assertSee('Demo sign-in')->assertSee('data-demo-mobile="9000000002"', false);
    $this->get('/manual')->assertOk()->assertSee('Demo sign-in')->assertSee('9000001124');

    app()->detectEnvironment(fn () => 'production');
    $this->get('/login')->assertOk()->assertDontSee('Demo sign-in');
    $this->get('/manual')->assertOk()->assertDontSee('Demo sign-in')->assertDontSee('9000000001');
});
