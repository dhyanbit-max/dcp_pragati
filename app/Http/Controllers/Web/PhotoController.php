<?php

namespace App\Http\Controllers\Web;

use App\Domain\Progress\PhotoService;
use App\Http\Controllers\Controller;
use App\Models\ProgressPhoto;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Serves a progress photo after the jurisdiction check (photos are never public). */
class PhotoController extends Controller
{
    public function show(ProgressPhoto $photo, PhotoService $photos): BinaryFileResponse
    {
        // Route binding already applied JurisdictionScope; out-of-scope photos 404.
        return response()->file($photos->absolutePath($photo), [
            'Cache-Control' => 'private, max-age=3600',
            'Content-Type' => 'image/jpeg',
        ]);
    }
}
