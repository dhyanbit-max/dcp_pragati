<?php

use App\Domain\Access\Role;
use App\Models\User;
use App\Models\Work;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\RuleAndAlertDefaultsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        $this->seed([RolesAndPermissionsSeeder::class, RuleAndAlertDefaultsSeeder::class]);
    })
    ->in('Feature');

/** Create a user with a role and optional jurisdiction rows. */
function userWithRole(string $role, array ...$jurisdictions): User
{
    $user = User::factory()->create();
    $user->assignRole($role);
    foreach ($jurisdictions as $j) {
        $user->jurisdictions()->create($j);
    }

    return $user;
}

/** A work with a site, a ₹50,000 release and an assigned field user. Returns [Work, User]. */
function workWithFieldUser(array $attrs = [], ?User $field = null, float $lat = 26.1445, float $lng = 91.7362): array
{
    $field ??= userWithRole(Role::FIELD_USER);
    $work = Work::factory()->create($attrs + ['assigned_field_user_id' => $field->id]);
    $work->setSite($lat, $lng);
    $work->releases()->create(['amount' => 50_000_00, 'released_on' => now()->subMonth()->toDateString()]);

    return [$work->fresh(), $field];
}

function fakeJpeg(): UploadedFile
{
    return UploadedFile::fake()->image(Str::random(8).'.jpg', 64, 48);
}

/** Upload a photo through the API as $user (close to the default site unless overridden). Returns its client_uuid. */
function uploadPhoto($test, User $user, Work $work, array $meta = []): string
{
    $uuid = (string) Str::uuid();
    $test->actingAs($user, 'sanctum')->post('/api/v1/photos', $meta + [
        'client_uuid' => $uuid,
        'work_id' => $work->id,
        'file' => fakeJpeg(),
        'lat' => 26.1446,
        'lng' => 91.7363,
        'accuracy_m' => 8,
        'taken_at' => now()->subMinutes(5)->toIso8601String(),
    ], ['Accept' => 'application/json'])->assertSuccessful();

    return $uuid;
}

function stageId(Work $work, int $seq): int
{
    return $work->stageTemplate->items()->where('seq', $seq)->value('id');
}
