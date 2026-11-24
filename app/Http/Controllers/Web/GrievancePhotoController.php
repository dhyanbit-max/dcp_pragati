<?php

namespace App\Http\Controllers\Web;

use App\Domain\Grievances\GrievanceService;
use App\Http\Controllers\Controller;
use App\Models\Grievance;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** A grievance photo, for officers who can see the grievance (route binding applies GrievanceScope). Never public. */
class GrievancePhotoController extends Controller
{
    public function __invoke(Grievance $grievance, GrievanceService $service): BinaryFileResponse
    {
        abort_unless($grievance->photo_path, 404);

        return response()->file($service->photoPath($grievance), ['Cache-Control' => 'private, max-age=3600']);
    }
}
