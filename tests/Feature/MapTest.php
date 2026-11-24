<?php

use App\Domain\Access\Role;
use App\Domain\Gis\MapData;
use App\Livewire\Map\WorksMap;
use App\Models\District;
use Database\Seeders\StatesSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->dA = District::create(['lgd_code' => 'MA', 'name_en' => 'Map A']);
    $this->dB = District::create(['lgd_code' => 'MB', 'name_en' => 'Map B']);
    [$this->wA, $this->fieldA] = workWithFieldUser(['district_id' => $this->dA->id]);
    [$this->wA2] = workWithFieldUser(['district_id' => $this->dA->id, 'target_completion_date' => now()->subDays(30)->toDateString()]);
    [$this->wB] = workWithFieldUser(['district_id' => $this->dB->id], null, 26.75, 94.2);
    $this->dpcA = userWithRole(Role::DPC, ['level' => 'district', 'district_id' => $this->dA->id]);
    DB::update('UPDATE districts SET boundary = ST_Multi(ST_Buffer(ST_SetSRID(ST_MakePoint(91.74, 26.14), 4326), 0.2)) WHERE id = ?', [$this->dA->id]);
    DB::update('UPDATE districts SET boundary = ST_Multi(ST_Buffer(ST_SetSRID(ST_MakePoint(94.2, 26.75), 4326), 0.2)) WHERE id = ?', [$this->dB->id]);
});

it('serves works as GeoJSON within the viewer\'s district, with map status', function () {
    $json = $this->actingAs($this->dpcA)->getJson(route('map.works'))
        ->assertOk()->assertHeader('Content-Type', 'application/geo+json')->json();

    $codes = collect($json['features'])->pluck('properties.code');
    expect($codes->all())->toEqualCanonicalizing([$this->wA->code, $this->wA2->code])
        ->and($json['features'][0]['geometry']['type'])->toBe('Point')
        ->and(collect($json['features'])->firstWhere('properties.code', $this->wA2->code)['properties']['display'])->toBe('delayed');
});

it('maps stored status and flags to one display state', function () {
    expect(MapData::display('in_progress', true, true))->toBe('stalled')
        ->and(MapData::display('in_progress', true, false))->toBe('delayed')
        ->and(MapData::display('completed', true, false))->toBe('completed')
        ->and(MapData::display('not_started', false, false))->toBe('not_started');
});

it('serves area boundaries with the share of works delayed or stalled', function () {
    $json = $this->actingAs(userWithRole(Role::STATE_VIEWER))->getJson(route('map.boundaries', 'district'))->assertOk()->json();

    $a = collect($json['features'])->firstWhere('properties.name', 'Map A');
    expect($json['features'])->toHaveCount(2)
        ->and($a['geometry']['type'])->toBeIn(['Polygon', 'MultiPolygon'])
        ->and($a['properties'])->toMatchArray(['works' => 2, 'flagged' => 1, 'flagged_pct' => 50.0]);

    $dpc = $this->actingAs($this->dpcA)->getJson(route('map.boundaries', 'district'))->json();
    expect(collect($dpc['features'])->pluck('properties.name')->all())->toBe(['Map A']);
});

it('returns work details with photo locations, and hides other districts', function () {
    $this->actingAs($this->fieldA, 'sanctum')->postJson('/api/v1/progress-updates', [
        'client_uuid' => (string) Str::uuid(), 'work_id' => $this->wA->id, 'stage_item_id' => stageId($this->wA, 2),
        'physical_pct' => 20, 'spent_cumulative' => 20_000_00, 'captured_at' => now()->toIso8601String(),
        'photo_uuids' => [uploadPhoto($this, $this->fieldA, $this->wA), uploadPhoto($this, $this->fieldA, $this->wA, ['lat' => 26.2, 'lng' => 91.8])],
    ])->assertCreated();
    app('auth')->forgetGuards();

    $json = $this->actingAs($this->dpcA)->getJson(route('map.work', $this->wA))->assertOk()->json();
    expect($json['site'])->toMatchArray(['lat' => 26.1445, 'lng' => 91.7362])
        ->and($json['photos'])->toHaveCount(2)
        ->and(collect($json['photos'])->where('outside', true))->toHaveCount(1);

    $this->getJson(route('map.work', $this->wB))->assertNotFound();
});

it('renders the map page and tells the map when filters change', function () {
    $this->actingAs($this->dpcA)->get(route('map'))->assertOk()->assertSee('works-map', false);

    Livewire::actingAs($this->dpcA)->test(WorksMap::class)
        ->assertSee('Map A')
        ->set('level', 'none')
        ->assertDispatched('map-filters', level: 'none');
});

it('imports boundaries from GeoJSON and reports unmatched areas', function () {
    $path = tempnam(sys_get_temp_dir(), 'b').'.geojson';
    file_put_contents($path, json_encode(['type' => 'FeatureCollection', 'features' => [
        ['type' => 'Feature', 'properties' => ['LGD' => 'MA'], 'geometry' => ['type' => 'Polygon', 'coordinates' => [[[91, 26], [92, 26], [92, 27], [91, 27], [91, 26]]]]],
        ['type' => 'Feature', 'properties' => ['LGD' => 'ZZ'], 'geometry' => ['type' => 'Polygon', 'coordinates' => [[[91, 26], [92, 26], [92, 27], [91, 26]]]]],
        ['type' => 'Feature', 'properties' => ['LGD' => 'MB'], 'geometry' => ['type' => 'Point', 'coordinates' => [91, 26]]],
    ]]));

    $this->artisan('geo:import-boundaries', ['level' => 'district', 'file' => $path, '--property' => 'LGD'])
        ->expectsOutputToContain('Boundaries updated: 1')
        ->expectsOutputToContain('No matching area for: ZZ')
        ->assertSuccessful();

    expect(DB::selectOne('SELECT ST_Area(boundary) AS a FROM districts WHERE id = ?', [$this->dA->id])->a)->toEqual(1.0);
});

it('serves the Assam outline once imported, and nothing before', function () {
    $this->seed(StatesSeeder::class);
    $this->actingAs($this->dpcA)->getJson(route('map.state'))->assertOk()->assertJsonCount(0, 'features');

    $path = tempnam(sys_get_temp_dir(), 's').'.geojson';
    file_put_contents($path, json_encode(['type' => 'FeatureCollection', 'features' => [
        ['type' => 'Feature', 'properties' => ['code' => '18'], 'geometry' => ['type' => 'Polygon', 'coordinates' => [[[89.7, 24.1], [96.0, 24.1], [96.0, 28.0], [89.7, 28.0], [89.7, 24.1]]]]],
    ]]));
    $this->artisan('geo:import-boundaries', ['level' => 'state', 'file' => $path, '--property' => 'code', '--source' => 'Test fixture'])
        ->expectsOutputToContain('Boundaries updated: 1')->assertSuccessful();

    $this->getJson(route('map.state'))->assertOk()
        ->assertJsonPath('features.0.properties.name', 'Assam')
        ->assertJsonPath('features.0.properties.source', 'Test fixture');
});

it('serves every loaded district and co-district border as plain lines, with names and no figures', function () {
    $empty = District::create(['lgd_code' => 'MC', 'name_en' => 'Map C', 'name_as' => 'মানচিত্ৰ গ']);
    DB::update('UPDATE districts SET boundary = ST_Multi(ST_Buffer(ST_SetSRID(ST_MakePoint(92.5, 26.4), 4326), 0.2)) WHERE id = ?', [$empty->id]);

    $json = $this->actingAs($this->dpcA)->getJson(route('map.borders', ['level' => 'district']))
        ->assertOk()->assertHeader('Content-Type', 'application/geo+json')->json();

    expect(collect($json['features'])->pluck('properties.name')->all())->toBe(['Map A', 'Map B', 'Map C'])
        ->and($json['features'][0]['geometry']['type'])->toBeIn(['Polygon', 'MultiPolygon'])
        ->and(array_keys($json['features'][0]['properties']))->toBe(['id', 'name', 'parent_id']);

    app()->setLocale('as');
    expect(collect(app(MapData::class)->borders('district')['features'])->pluck('properties.name'))->toContain('মানচিত্ৰ গ');

    $this->actingAs($this->dpcA)->getJson(route('map.borders', ['level' => 'co_district']))->assertOk()->assertJsonPath('features', []);
    $this->actingAs($this->dpcA)->get('/map/borders/state.geojson')->assertNotFound();
    auth()->logout();
    $this->get(route('map.borders', ['level' => 'district']))->assertRedirect(route('login'));
});
