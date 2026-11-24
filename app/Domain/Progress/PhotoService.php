<?php

namespace App\Domain\Progress;

use App\Models\ProgressPhoto;
use App\Models\User;
use App\Models\Work;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/** Stores a progress photo (private disk) and records its location facts. Idempotent on client_uuid. */
class PhotoService
{
    public const DISK = 'local';

    public function store(User $user, Work $work, UploadedFile $file, array $meta): ProgressPhoto
    {
        if ($existing = ProgressPhoto::withoutGlobalScopes()->where('client_uuid', $meta['client_uuid'])->first()) {
            return $existing;
        }

        $sha = hash_file('sha256', $file->getRealPath());
        $path = $file->storeAs(
            sprintf('photos/%d/%s', $work->id, now()->format('Y/m')),
            $meta['client_uuid'].'.jpg',
            self::DISK,
        );
        [$width, $height] = @getimagesize($file->getRealPath()) ?: [null, null];

        // Web uploads carry no device fields; fall back to the photo's own EXIF GPS and capture time.
        if (($meta['source'] ?? 'app') === 'web') {
            $meta += $this->exifFacts($file->getRealPath());
        }

        $lat = isset($meta['lat']) ? (float) $meta['lat'] : null;
        $lng = isset($meta['lng']) ? (float) $meta['lng'] : null;

        return ProgressPhoto::create([
            'client_uuid' => $meta['client_uuid'],
            'work_id' => $work->id,
            'uploaded_by' => $user->id,
            'path' => $path,
            'sha256' => $sha,
            'width' => $width,
            'height' => $height,
            'lat' => $lat,
            'lng' => $lng,
            'accuracy_m' => $meta['accuracy_m'] ?? null,
            'is_mocked' => (bool) ($meta['is_mocked'] ?? false),
            'source' => $meta['source'] ?? 'app',
            // App time zone: Eloquent drops the offset when it stores dates (see ProgressInput).
            'taken_at' => isset($meta['taken_at']) ? Carbon::parse($meta['taken_at'])->setTimezone(config('app.timezone')) : null,
            'distance_from_site_m' => ($lat !== null && $lng !== null) ? $work->distanceTo($lat, $lng) : null,
        ]);
    }

    /** @return array{lat?: float, lng?: float, taken_at?: string} */
    public function exifFacts(string $path): array
    {
        if (! function_exists('exif_read_data')) {
            return [];
        }
        $exif = @exif_read_data($path, 'GPS,EXIF', true);
        if (! is_array($exif)) {
            return [];
        }

        $out = [];
        $gps = $exif['GPS'] ?? [];
        if (isset($gps['GPSLatitude'], $gps['GPSLongitude'], $gps['GPSLatitudeRef'], $gps['GPSLongitudeRef'])) {
            $lat = $this->dms($gps['GPSLatitude']);
            $lng = $this->dms($gps['GPSLongitude']);
            if ($lat !== null && $lng !== null) {
                $out['lat'] = $gps['GPSLatitudeRef'] === 'S' ? -$lat : $lat;
                $out['lng'] = $gps['GPSLongitudeRef'] === 'W' ? -$lng : $lng;
            }
        }
        $taken = $exif['EXIF']['DateTimeOriginal'] ?? null;
        if (is_string($taken) && ($d = \DateTime::createFromFormat('Y:m:d H:i:s', $taken, new \DateTimeZone(config('app.timezone'))))) {
            $out['taken_at'] = $d->format(DATE_ATOM);
        }

        return $out;
    }

    /** EXIF degrees/minutes/seconds rationals ("26/1", "8/1", "4020/100") → decimal degrees. */
    private function dms(array $parts): ?float
    {
        $v = array_map(function ($r) {
            [$n, $d] = array_pad(explode('/', (string) $r), 2, 1);

            return (float) $d == 0.0 ? null : (float) $n / (float) $d;
        }, array_slice($parts, 0, 3));

        return in_array(null, $v, true) || count($v) < 3 ? null : $v[0] + $v[1] / 60 + $v[2] / 3600;
    }

    public function absolutePath(ProgressPhoto $photo): string
    {
        return Storage::disk(self::DISK)->path($photo->path);
    }
}
